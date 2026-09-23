// Tailscale Unix relay forwards the Laravel read-only MT5 bridge calls from a
// Unix-domain socket through a Tailnet-only route. It is intended for shared
// hosting where there is no TUN device, no permitted local TCP listener, and
// PHP process-execution functions are disabled.
package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"os"
	"path/filepath"
	"time"
)

const maxRequestBytes = 128 * 1024

var (
	socketPath      = flag.String("socket", defaultPath(".local/share/nexa-bridge/relay.sock"), "Unix socket to listen on")
	tailscaledSock  = flag.String("tailscaled-socket", defaultPath(".local/share/tailscale/tailscaled.sock"), "Tailscaled Unix socket")
	targetHost      = flag.String("target-host", "mahesh-paturi", "Tailnet host serving the MT5 bridge")
	targetPort      = flag.String("target-port", "8765", "Tailnet MT5 bridge port")
	requestTimeout  = flag.Duration("timeout", 12*time.Second, "Upstream request timeout")
)

func defaultPath(relative string) string {
	home, err := os.UserHomeDir()
	if err != nil {
		return relative
	}
	return filepath.Join(home, relative)
}

func main() {
	flag.Parse()
	if err := os.MkdirAll(filepath.Dir(*socketPath), 0o700); err != nil {
		log.Fatalf("create socket directory: %v", err)
	}
	if info, err := os.Lstat(*socketPath); err == nil {
		if info.Mode()&os.ModeSocket == 0 {
			log.Fatalf("refusing to replace non-socket path: %s", *socketPath)
		}
		if err := os.Remove(*socketPath); err != nil {
			log.Fatalf("remove stale socket: %v", err)
		}
	} else if !errors.Is(err, os.ErrNotExist) {
		log.Fatalf("inspect socket path: %v", err)
	}

	listener, err := net.Listen("unix", *socketPath)
	if err != nil {
		log.Fatalf("listen on Unix socket: %v", err)
	}
	defer listener.Close()
	defer os.Remove(*socketPath)
	if err := os.Chmod(*socketPath, 0o600); err != nil {
		log.Fatalf("protect socket: %v", err)
	}
	log.Printf("private MT5 relay listening on %s", *socketPath)

	for {
		connection, err := listener.Accept()
		if err != nil {
			log.Printf("accept connection: %v", err)
			continue
		}
		go handle(connection)
	}
}

func handle(connection net.Conn) {
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(*requestTimeout))

	request, err := http.ReadRequest(bufio.NewReader(&ioLimitReader{Reader: connection, limit: maxRequestBytes}))
	if err != nil {
		writeError(connection, http.StatusBadRequest, "invalid bridge request")
		return
	}
	defer request.Body.Close()
	if request.Method != http.MethodGet {
		writeError(connection, http.StatusMethodNotAllowed, "read-only relay")
		return
	}

	payload, err := httputil.DumpRequest(request, true)
	if err != nil || len(payload) > maxRequestBytes {
		writeError(connection, http.StatusBadRequest, "invalid bridge request")
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), *requestTimeout)
	defer cancel()
	upstream, err := dialTailnet(ctx)
	if err != nil {
		if ctx.Err() != nil {
			writeError(connection, http.StatusGatewayTimeout, "private bridge timed out")
			return
		}
		log.Printf("tailscale dial failed: %v", err)
		writeError(connection, http.StatusBadGateway, "private bridge unavailable")
		return
	}
	defer upstream.Close()
	if _, err := upstream.Write(payload); err != nil {
		log.Printf("private bridge write failed: %v", err)
		writeError(connection, http.StatusBadGateway, "private bridge unavailable")
		return
	}
	if _, err := io.Copy(connection, upstream); err != nil && !errors.Is(err, net.ErrClosed) {
		log.Printf("private bridge response failed: %v", err)
	}
}

func dialTailnet(ctx context.Context) (net.Conn, error) {
	dialer := net.Dialer{}
	connection, err := dialer.DialContext(ctx, "unix", *tailscaledSock)
	if err != nil {
		return nil, err
	}

	request := fmt.Sprintf(
		"POST /localapi/v0/dial HTTP/1.1\r\nHost: local-tailscaled.sock\r\nConnection: upgrade\r\nUpgrade: ts-dial\r\nDial-Host: %s\r\nDial-Port: %s\r\nDial-Network: tcp\r\nContent-Length: 0\r\n\r\n",
		*targetHost,
		*targetPort,
	)
	if _, err := io.WriteString(connection, request); err != nil {
		connection.Close()
		return nil, err
	}

	reader := bufio.NewReader(connection)
	response, err := http.ReadResponse(reader, &http.Request{Method: http.MethodPost})
	if err != nil {
		connection.Close()
		return nil, err
	}
	if response.StatusCode != http.StatusSwitchingProtocols {
		body, _ := io.ReadAll(io.LimitReader(response.Body, 4096))
		response.Body.Close()
		connection.Close()
		return nil, fmt.Errorf("Tailscale dial rejected: %s %s", response.Status, string(body))
	}
	return &bufferedConnection{Conn: connection, reader: reader}, nil
}

// bufferedConnection preserves response bytes that arrived alongside the local
// API upgrade response before exposing the upgraded Tailnet connection.
type bufferedConnection struct {
	net.Conn
	reader *bufio.Reader
}

func (connection *bufferedConnection) Read(buffer []byte) (int, error) {
	return connection.reader.Read(buffer)
}

// ioLimitReader stops a peer from streaming an unbounded request before the
// relay has a chance to validate it.
type ioLimitReader struct {
	Reader net.Conn
	limit  int
	read   int
}

func (reader *ioLimitReader) Read(buffer []byte) (int, error) {
	if reader.read >= reader.limit {
		return 0, fmt.Errorf("request exceeds %d bytes", reader.limit)
	}
	if remaining := reader.limit - reader.read; len(buffer) > remaining {
		buffer = buffer[:remaining]
	}
	n, err := reader.Reader.Read(buffer)
	reader.read += n
	return n, err
}

func writeError(connection net.Conn, status int, message string) {
	body := []byte(fmt.Sprintf(`{"error":"%s"}`, message))
	_, _ = fmt.Fprintf(connection, "HTTP/1.1 %d %s\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: %d\r\n\r\n", status, http.StatusText(status), len(body))
	_, _ = connection.Write(body)
}
