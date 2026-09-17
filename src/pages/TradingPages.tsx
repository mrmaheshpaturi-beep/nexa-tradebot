import { useCallback, useState } from "react";
import { AlertOctagon, ArrowDownRight, ArrowUpRight, Check, ChevronRight, CircleOff, Eye, Filter, LockKeyhole, Pencil, Play, Plus, Power, ShieldCheck, Star, X } from "lucide-react";
import { services } from "../services/mockServices";
import { useService } from "../hooks/useService";
import { AIScoreGauge, ConfirmationDialog, DataTable, DirectionBadge, EnvironmentBadge, ErrorState, FilterBar, LoadingState, MetricCard, PageHeader, Panel, PnLDisplay, RiskGauge, StatusBadge } from "../components/ui";
import { CandlestickTerminal, EquityChart, PerformanceChart } from "../components/TradingCharts";
import type { BacktestConfig, Position, Quote, Signal } from "../domain/types";

const Button = ({ children, tone = "", onClick, disabled = false }: { children: React.ReactNode; tone?: string; onClick?: () => void; disabled?: boolean }) => (
  <button className={`btn ${tone}`} onClick={onClick} disabled={disabled}>
    {children}
  </button>
);
const Select = ({ children, id, label }: { children: React.ReactNode; id: string; label: string }) => (
  <label className="field" htmlFor={id}>
    <span>{label}</span>
    <select id={id} name={id}>
      {children}
    </select>
  </label>
);

export function Dashboard() {
  const loadAccount = useCallback(() => services.account.getSnapshot(), []);
  const loadEquity = useCallback(() => services.analytics.getEquitySeries(), []);
  const loadSignals = useCallback(() => services.signals.getSignals(), []);
  const { data, loading, error } = useService(loadAccount);
  const equity = useService(loadEquity);
  const signals = useService(loadSignals);
  if (loading) return <LoadingState />;
  if (error || !data) return <ErrorState message={error ?? "No account snapshot available."} />;
  const metrics = [
    ["Account Balance", `$${data.balance.toLocaleString()}`, "Demo capital"],
    ["Equity", `$${data.equity.toLocaleString()}`, "+0.59% vs balance"],
    ["Today's P/L", <PnLDisplay key="today-pnl" value={data.todayPnl} />, "+1.49%"],
    ["Weekly P/L", <PnLDisplay key="weekly-pnl" value={data.weeklyPnl} />, "+5.27%"],
    ["Floating P/L", <PnLDisplay key="floating-pnl" value={data.floatingPnl} />, "5 open positions"],
    ["Free Margin", `$${data.freeMargin.toLocaleString()}`, "94.2% available"],
    ["Margin Level", `${data.marginLevel}%`, "Healthy"],
    ["Current Drawdown", `${data.drawdown}%`, "Limit 12%"],
  ] as const;
  return (
    <>
      <PageHeader
        title="Trading Overview"
        description="Real-time operational view of your simulated trading environment."
        actions={
          <>
            <Button tone="ghost">
              <Filter size={15} /> Customize
            </Button>
            <Button tone="primary">
              <Play size={15} /> Run simulation
            </Button>
          </>
        }
      />
      <div className="status-ribbon">
        <div>
          <span className="status-dot" />
          <p>
            <small>TRADING ENVIRONMENT</small>
            <strong>SIMULATION</strong>
          </p>
        </div>
        <div>
          <CircleOff />
          <p>
            <small>MT5 CONNECTION</small>
            <strong>NOT CONNECTED</strong>
          </p>
        </div>
        <div>
          <ShieldCheck />
          <p>
            <small>RISK ENGINE</small>
            <strong>SIMULATION ACTIVE</strong>
          </p>
        </div>
        <div>
          <span className="status-dot" />
          <p>
            <small>MOCK DATA</small>
            <strong>STREAMING</strong>
          </p>
        </div>
      </div>
      <div className="metrics-grid">
        {metrics.map(([label, value, detail]) => (
          <MetricCard key={label} label={label} value={value} detail={detail} />
        ))}
      </div>
      <div className="secondary-metrics">
        {[
          ["Open Positions", data.openPositions],
          ["Pending Orders", data.pendingOrders],
          ["Active Strategies", data.activeStrategies],
          ["Today's Trades", data.todayTrades],
          ["Win Rate", `${data.winRate}%`],
          ["Open Risk", `${data.openRisk}%`],
        ].map(([l, v]) => (
          <div key={l}>
            <span>{l}</span>
            <strong>{v}</strong>
          </div>
        ))}
      </div>
      <div className="two-thirds-grid">
        <Panel title="Account Growth" subtitle="Simulation equity curve · Jan–Dec 2026" actions={<StatusBadge tone="good">+26.2%</StatusBadge>}>
          {equity.data ? <EquityChart data={equity.data} /> : <LoadingState />}
        </Panel>
        <Panel title="Risk Summary" subtitle="Current profile usage">
          <div className="gauge-list">
            <RiskGauge label="Daily loss" value={1.2} limit={4} />
            <RiskGauge label="Open risk" value={3.2} limit={6} />
            <RiskGauge label="Drawdown" value={2.4} limit={12} />
            <RiskGauge label="Margin level" value={1846} limit={300} inverse />
          </div>
        </Panel>
      </div>
      <div className="three-grid">
        <Panel title="Daily P/L" subtitle="This simulation week">
          <PerformanceChart />
        </Panel>
        <Panel title="Recent signals" subtitle="Display-only AI analysis">
          <div className="compact-list">
            {signals.data?.map((s) => (
              <div key={s.id}>
                <DirectionBadge value={s.direction} />
                <p>
                  <strong>{s.symbol}</strong>
                  <small>
                    {s.strategy} · {s.timeframe}
                  </small>
                </p>
                <b>{s.score}/100</b>
              </div>
            ))}
          </div>
        </Panel>
        <Panel title="System health" subtitle="Phase 1 services">
          <div className="compact-list">
            {[
              ["Web application", "ONLINE"],
              ["Market data", "MOCK"],
              ["Trading engine", "SIMULATION"],
              ["MT5 / Broker", "NOT CONNECTED"],
            ].map(([a, b]) => (
              <div key={a}>
                <span className={b === "NOT CONNECTED" ? "status-dot off" : "status-dot"} />
                <p>
                  <strong>{a}</strong>
                  <small>{b}</small>
                </p>
              </div>
            ))}
          </div>
        </Panel>
      </div>
    </>
  );
}

export function MarketWatch() {
  const loader = useCallback(() => services.market.getQuotes(), []);
  const { data, loading, error } = useService(loader);
  const [search, setSearch] = useState("");
  const [asset, setAsset] = useState("All");
  if (loading) return <LoadingState />;
  if (error || !data) return <ErrorState message={error ?? ""} />;
  const filtered = data.filter(
    (q) =>
      q.symbol.includes(search.toUpperCase()) &&
      (asset === "All" ||
        (
          {
            XAUUSD: "Metals",
            US30: "Indices",
            NAS100: "Indices",
            SPX500: "Indices",
            BTCUSD: "Crypto",
            ETHUSD: "Crypto",
          } as Record<string, string>
        )[q.symbol] === asset ||
        (asset === "Forex" && !["XAUUSD", "US30", "NAS100", "SPX500", "BTCUSD", "ETHUSD"].includes(q.symbol))),
  );
  return (
    <>
      <PageHeader title="Market Watch" description="Streaming mock quotes across forex, metals, indices, and crypto." actions={<EnvironmentBadge />} />
      <Panel title="Instruments" subtitle={`${filtered.length} of ${data.length} symbols`}>
        <FilterBar search={search} searchId="market-watch-search" onSearch={setSearch}>
          {["All", "Forex", "Metals", "Indices", "Crypto"].map((a) => (
            <button key={a} className={`chip ${asset === a ? "active" : ""}`} onClick={() => setAsset(a)}>
              {a}
            </button>
          ))}
        </FilterBar>
        <QuoteTable quotes={filtered} />
      </Panel>
    </>
  );
}
function QuoteTable({ quotes }: { quotes: Quote[] }) {
  return (
    <DataTable
      columns={["", "Symbol", "Bid", "Ask", "Spread", "Change %", "High", "Low", "Trend", "Volatility", "Market"]}
      rows={quotes.map((q) => [
        <Star key={`${q.symbol}-favorite`} size={15} />,
        <strong key={`${q.symbol}-symbol`}>{q.symbol}</strong>,
        q.bid,
        q.ask,
        q.spread,
        <span key={`${q.symbol}-change`} className={q.change >= 0 ? "positive" : "negative"}>
          {q.change}%
        </span>,
        q.high,
        q.low,
        <StatusBadge key={`${q.symbol}-trend`} tone={q.trend === "Bullish" ? "good" : q.trend === "Bearish" ? "bad" : "neutral"}>
          {q.trend}
        </StatusBadge>,
        q.volatility,
        <StatusBadge key={`${q.symbol}-market`} tone="good">
          {q.marketStatus}
        </StatusBadge>,
      ])}
    />
  );
}

export function MarketScanner() {
  const loader = useCallback(() => services.market.getQuotes(), []);
  const { data, loading } = useService(loader);
  if (loading || !data) return <LoadingState />;
  return (
    <>
      <PageHeader title="Market Scanner" description="Ranked multi-timeframe opportunities from deterministic simulation rules." />
      <Panel title="Scanner results" subtitle="Scores are illustrative and do not predict trade quality">
        <FilterBar>
          {["Asset", "Timeframe", "Strategy", "Signal", "Score", "Regime"].map((x) => (
            <Button key={x} tone="ghost">
              {x}
            </Button>
          ))}
        </FilterBar>
        <DataTable
          columns={["Symbol", "Timeframe", "Trend", "Momentum", "Volatility", "Spread", "Regime", "Strategy", "Signal", "Score", "Updated"]}
          rows={data.slice(0, 10).map((q, i) => [
            <strong key={`${q.symbol}-symbol`}>{q.symbol}</strong>,
            ["M15", "H1", "H4"][i % 3],
            <StatusBadge key={`${q.symbol}-trend`} tone={q.trend === "Bullish" ? "good" : q.trend === "Bearish" ? "bad" : "neutral"}>
              {q.trend}
            </StatusBadge>,
            i % 2 ? "Falling" : "Rising",
            q.volatility,
            q.spread,
            i % 3 ? "Trending" : "Range",
            ["EMA Pullback", "Breakout", "Market Structure"][i % 3],
            <DirectionBadge key={`${q.symbol}-direction`} value={i % 3 === 0 ? "BUY" : i % 3 === 1 ? "SELL" : "NO TRADE"} />,
            <b key={`${q.symbol}-score`}>{92 - i * 4}</b>,
            `${i + 1}m ago`,
          ])}
        />
      </Panel>
    </>
  );
}

export function AISignals() {
  const loader = useCallback(() => services.signals.getSignals(), []);
  const { data, loading, error } = useService(loader);
  const [filter, setFilter] = useState("All");
  if (loading) return <LoadingState />;
  if (error || !data) return <ErrorState message={error ?? ""} />;
  const shown = data.filter((s) => filter === "All" || s.direction === filter || (filter === "High Confidence" && s.score >= 85));
  return (
    <>
      <PageHeader title="AI Signal Center" description="Explainable mock scoring for interface validation — no real AI or execution." actions={<StatusBadge tone="purple">SIMULATED AI</StatusBadge>} />
      <div className="score-legend">
        <b>Display-only score:</b>
        <span>0–49 Weak</span>
        <span>50–69 Watch</span>
        <span>70–84 Strong Setup</span>
        <span>85–100 High Confluence</span>
      </div>
      <FilterBar>
        {["All", "High Confidence", "BUY", "SELL", "NO TRADE", "Forex", "Metals", "Indices", "Crypto"].map((f) => (
          <button key={f} className={`chip ${filter === f ? "active" : ""}`} onClick={() => setFilter(f)}>
            {f}
          </button>
        ))}
      </FilterBar>
      <div className="signal-grid">
        {shown.map((s) => (
          <SignalCard key={s.id} signal={s} />
        ))}
      </div>
    </>
  );
}
function SignalCard({ signal }: { signal: Signal }) {
  const [open, setOpen] = useState(false);
  return (
    <article className="signal-card">
      <div className="signal-card-head">
        <div>
          <span className="sim-label">SIMULATED SIGNAL</span>
          <h2>
            {signal.symbol} <DirectionBadge value={signal.direction} />
          </h2>
          <p>
            {signal.strategy} · {signal.timeframe} · {signal.regime}
          </p>
        </div>
        <AIScoreGauge score={signal.score} />
      </div>
      <div className="price-levels">
        {[
          ["Entry", signal.entry],
          ["Stop loss", signal.stopLoss],
          ["Take profit 1", signal.takeProfit1],
          ["Take profit 2", signal.takeProfit2],
        ].map(([l, v]) => (
          <div key={l}>
            <span>{l}</span>
            <strong>{v}</strong>
          </div>
        ))}
      </div>
      <div className="rr">
        <span>Simulated risk / reward</span>
        <strong>1 : {signal.riskReward}</strong>
      </div>
      <button className="expand-btn" onClick={() => setOpen(!open)}>
        {open ? "Hide" : "View"} analysis <ChevronRight size={15} />
      </button>
      {open && (
        <div className="analysis-grid">
          {Object.entries(signal.analysis).map(([k, v]) => (
            <div key={k}>
              <span>{k}</span>
              <p>{v}</p>
            </div>
          ))}
        </div>
      )}
    </article>
  );
}

export function LiveCharts() {
  const [symbol, setSymbol] = useState("XAUUSD");
  const loader = useCallback(() => services.market.getCandles(symbol), [symbol]);
  const { data, loading } = useService(loader);
  return (
    <>
      <PageHeader title="Live Charts" description="Interactive mock OHLC workspace with simulation entry and risk overlays." actions={<EnvironmentBadge />} />
      <div className="chart-layout">
        <Panel
          title={`${symbol} · H1`}
          subtitle="MOCK MARKET DATA"
          actions={
            <div className="inline-controls">
              <select id="chart-symbol" name="chart-symbol" aria-label="Chart symbol" value={symbol} onChange={(e) => setSymbol(e.target.value)}>
                <option>XAUUSD</option>
                <option>EURUSD</option>
                <option>NAS100</option>
              </select>
              {["M1", "M5", "M15", "M30", "H1", "H4", "D1"].map((t) => (
                <button key={t} className={t === "H1" ? "active" : ""}>
                  {t}
                </button>
              ))}
            </div>
          }
        >
          {loading || !data ? <LoadingState /> : <CandlestickTerminal candles={data} />}
          <div className="indicator-row">
            {["EMA 20", "EMA 50", "EMA 200", "RSI", "MACD", "ATR", "Bollinger Bands"].map((x) => (
              <span key={x}>{x}</span>
            ))}
          </div>
          <div className="rsi-placeholder">
            <span>RSI (14)</span>
            <svg viewBox="0 0 500 50" preserveAspectRatio="none">
              <polyline fill="none" stroke="#a879ff" strokeWidth="2" points="0,35 50,24 100,31 150,14 200,20 250,9 300,25 350,18 400,32 450,20 500,27" />
            </svg>
          </div>
        </Panel>
        <Panel title="Market information" subtitle="Simulated quote">
          <div className="market-info">
            {[
              ["Bid", "2,642.18"],
              ["Ask", "2,642.58"],
              ["Spread", "1.8"],
              ["Daily high", "2,653.42"],
              ["Daily low", "2,617.81"],
              ["Daily change", "+0.74%"],
              ["Market status", "OPEN"],
            ].map(([l, v]) => (
              <div key={l}>
                <span>{l}</span>
                <strong>{v}</strong>
              </div>
            ))}
          </div>
          <hr />
          <h3>Chart overlays</h3>
          {["SIM Entry · 2642.20", "Stop Loss · 2631.40", "TP1 · 2658.40", "TP2 · 2671.80", "Support · 2624.00", "Resistance · 2660.00"].map((x) => (
            <label key={x} className="check-row" htmlFor={`overlay-${x.split(" ")[0].toLowerCase()}`}>
              <input id={`overlay-${x.split(" ")[0].toLowerCase()}`} name={`overlay-${x.split(" ")[0].toLowerCase()}`} type="checkbox" defaultChecked />
              {x}
            </label>
          ))}
        </Panel>
      </div>
    </>
  );
}

export function Strategies() {
  const loader = useCallback(() => services.strategies.getStrategies(), []);
  const { data, loading } = useService(loader);
  const [selected, setSelected] = useState<typeof data extends (infer U)[] | undefined ? U : never>();
  if (loading || !data) return <LoadingState />;
  return (
    <>
      <PageHeader
        title="Strategy Manager"
        description="Configure demonstration strategies without creating an execution path."
        actions={
          <Button tone="primary">
            <Plus size={15} /> New mock strategy
          </Button>
        }
      />
      <Panel title="Strategy library" subtitle="9 simulation-only strategy templates">
        <FilterBar>
          {["All", "Trend", "Momentum", "Breakout", "Reversal", "Scalping", "Swing", "AI Assisted", "Custom"].map((x) => (
            <button key={x} className="chip">
              {x}
            </button>
          ))}
        </FilterBar>
        <DataTable
          columns={["Strategy", "Category", "Symbols", "Timeframe", "Mode", "Status", "Signals", "Trades", "Win rate", "Profit factor", "Actions"]}
          rows={data.map((s) => [
            <button key={`${s.id}-name`} className="link" onClick={() => setSelected(s)}>
              {s.name}
            </button>,
            s.category,
            s.symbols.join(", "),
            s.timeframe,
            <StatusBadge key={`${s.id}-mode`} tone="info">
              {s.mode}
            </StatusBadge>,
            <StatusBadge key={`${s.id}-status`} tone={s.status === "Enabled" ? "good" : "neutral"}>
              {s.status}
            </StatusBadge>,
            s.signals,
            s.trades,
            `${s.winRate.toFixed(1)}%`,
            s.profitFactor.toFixed(2),
            <div key={`${s.id}-actions`} className="row-actions">
              <button title="View" onClick={() => setSelected(s)}>
                <Eye />
              </button>
              <button title="Edit">
                <Pencil />
              </button>
              <button title="Enable or disable">
                <Power />
              </button>
            </div>,
          ])}
        />
      </Panel>
      {selected && (
        <div className="drawer">
          <button className="drawer-close" onClick={() => setSelected(undefined)}>
            <X />
          </button>
          <span className="eyebrow">STRATEGY DETAIL</span>
          <h2>{selected.name}</h2>
          <p>{selected.description}</p>
          <div className="detail-list">
            {[
              ["Category", selected.category],
              ["Symbols", selected.symbols.join(", ")],
              ["Timeframe", selected.timeframe],
              ["Risk profile", "Balanced simulation"],
              ["Win rate", `${selected.winRate.toFixed(1)}%`],
              ["Profit factor", selected.profitFactor.toFixed(2)],
            ].map(([l, v]) => (
              <div key={l}>
                <span>{l}</span>
                <strong>{v}</strong>
              </div>
            ))}
          </div>
          <h3>Rules</h3>
          <ul>
            <li>Wait for deterministic setup confluence</li>
            <li>Validate mock risk thresholds</li>
            <li>Create a simulated signal only</li>
          </ul>
          <Button tone="primary">Duplicate template</Button>
        </div>
      )}
    </>
  );
}

export function AutoTrading() {
  return (
    <>
      <PageHeader title="Auto Trading" description="Future automation configuration preview — execution is permanently locked in Phase 1." actions={<EnvironmentBadge />} />
      <div className="lock-panel">
        <div className="lock-icon">
          <LockKeyhole />
        </div>
        <span className="eyebrow">PHASE 1 SAFETY LOCK</span>
        <h2>Auto trading is disabled</h2>
        <p>
          Real broker execution is not configured. Current environment: <strong>SIMULATION</strong>.
        </p>
        <button className="master-switch" disabled>
          <i />
          <span>MASTER SWITCH · DISABLED</span>
        </button>
      </div>
      <Panel title="Future automation settings" subtitle="Prepared interface only · controls do not connect to an engine">
        <div className="form-grid">
          <Select id="auto-account" label="Allowed account">
            <option>XM Demo (Not connected)</option>
          </Select>
          <Select id="auto-symbols" label="Allowed symbols">
            <option>XAUUSD, EURUSD, GBPUSD</option>
          </Select>
          <Select id="auto-strategies" label="Allowed strategies">
            <option>Enabled simulation strategies</option>
          </Select>
          <label className="field" htmlFor="auto-minimum-score">
            <span>Minimum signal score</span>
            <input id="auto-minimum-score" name="auto-minimum-score" type="number" value="85" readOnly />
          </label>
          <label className="field" htmlFor="auto-max-positions">
            <span>Max simultaneous positions</span>
            <input id="auto-max-positions" name="auto-max-positions" type="number" value="3" readOnly />
          </label>
          <Select id="auto-session" label="Trading session">
            <option>London + New York</option>
          </Select>
          <Select id="auto-risk-profile" label="Risk profile">
            <option>Balanced simulation</option>
          </Select>
          <Select id="auto-news-filter" label="News filter">
            <option>Block high impact</option>
          </Select>
        </div>
        <div className="protection-row">
          {["Spread protection", "Slippage protection", "Risk authority", "Session guard"].map((x) => (
            <div key={x}>
              <ShieldCheck />
              <p>
                <strong>{x}</strong>
                <small>Simulation preview</small>
              </p>
            </div>
          ))}
        </div>
      </Panel>
    </>
  );
}

export function ManualTrading() {
  const [direction, setDirection] = useState<"BUY" | "SELL">("BUY");
  const [volume, setVolume] = useState(0.4);
  const [risk, setRisk] = useState(1);
  const [confirmed, setConfirmed] = useState(false);
  const [ticket, setTicket] = useState("");
  const submit = async () => {
    const r = await services.orders.simulateOrder({
      symbol: "XAUUSD",
      direction,
      volume,
    });
    setTicket(r.ticket);
    setConfirmed(false);
  };
  return (
    <>
      <PageHeader title="Manual Trading Terminal" description="Build and validate virtual orders. No instruction can reach a broker." actions={<EnvironmentBadge />} />
      {ticket && (
        <div className="success-banner">
          <Check /> Simulated order {ticket} accepted. No broker action occurred.
        </div>
      )}
      <div className="ticket-layout">
        <Panel title="Simulation order ticket" subtitle="All fields are local demonstration inputs">
          <div className="direction-toggle">
            <button className={direction === "BUY" ? "buy active" : ""} onClick={() => setDirection("BUY")}>
              <ArrowUpRight /> BUY
            </button>
            <button className={direction === "SELL" ? "sell active" : ""} onClick={() => setDirection("SELL")}>
              <ArrowDownRight /> SELL
            </button>
          </div>
          <div className="form-grid">
            <Select id="manual-account" label="Account">
              <option>XM Demo · NOT CONNECTED</option>
            </Select>
            <Select id="manual-symbol" label="Symbol">
              <option>XAUUSD</option>
              <option>EURUSD</option>
            </Select>
            <Select id="manual-order-type" label="Order type">
              <option>Market</option>
              <option>Limit</option>
              <option>Stop</option>
            </Select>
            <label className="field" htmlFor="manual-volume">
              <span>Volume</span>
              <input id="manual-volume" name="manual-volume" type="number" value={volume} onChange={(e) => setVolume(Number(e.target.value))} />
            </label>
            <label className="field" htmlFor="manual-risk">
              <span>Risk %</span>
              <input id="manual-risk" name="manual-risk" type="number" value={risk} onChange={(e) => setRisk(Number(e.target.value))} />
            </label>
            <label className="field" htmlFor="manual-entry">
              <span>Entry</span>
              <input id="manual-entry" name="manual-entry" value="2642.20" readOnly />
            </label>
            <label className="field" htmlFor="manual-stop-loss">
              <span>Stop loss</span>
              <input id="manual-stop-loss" name="manual-stop-loss" value="2631.40" readOnly />
            </label>
            <label className="field" htmlFor="manual-take-profit">
              <span>Take profit</span>
              <input id="manual-take-profit" name="manual-take-profit" value="2671.80" readOnly />
            </label>
            <label className="field" htmlFor="manual-tp1">
              <span>Optional TP1</span>
              <input id="manual-tp1" name="manual-tp1" value="2658.40" readOnly />
            </label>
            <label className="field" htmlFor="manual-tp2">
              <span>Optional TP2</span>
              <input id="manual-tp2" name="manual-tp2" value="2671.80" readOnly />
            </label>
            <label className="field wide" htmlFor="manual-comment">
              <span>Comment</span>
              <input id="manual-comment" name="manual-comment" placeholder="Simulation note" />
            </label>
          </div>
          <Button tone={direction === "BUY" ? "buy" : "sell"} onClick={() => setConfirmed(true)}>
            SIMULATE {direction}
          </Button>
        </Panel>
        <Panel title="Risk calculation" subtitle="Approximate · simulation only">
          <div className="calculation">
            <div>
              <span>Risk amount</span>
              <strong>${((125480 * risk) / 100).toLocaleString()}</strong>
            </div>
            <div>
              <span>Potential profit</span>
              <strong className="positive">${(((125480 * risk) / 100) * 2.7).toLocaleString()}</strong>
            </div>
            <div>
              <span>Risk / reward</span>
              <strong>1 : 2.70</strong>
            </div>
            <div>
              <span>Approx. margin</span>
              <strong>${((2642 * volume) / 5).toFixed(2)}</strong>
            </div>
          </div>
          <div className="warning-box">
            <AlertOctagon />
            <p>
              <strong>No execution capability</strong>
              <span>This ticket calls an in-memory mock service. Frontend tampering cannot create broker connectivity.</span>
            </p>
          </div>
        </Panel>
      </div>
      <ConfirmationDialog open={confirmed} title={`Confirm simulated ${direction}`} onCancel={() => setConfirmed(false)} onConfirm={submit}>
        This creates a simulation record only. It will not place, transmit, or execute a live order.
      </ConfirmationDialog>
    </>
  );
}

export function Positions() {
  const loader = useCallback(() => services.positions.getPositions(), []);
  const { data, loading } = useService(loader);
  const [action, setAction] = useState<{ name: string; p: Position } | null>(null);
  if (loading || !data) return <LoadingState />;
  return (
    <>
      <PageHeader title="Open Positions" description="Monitor and manage virtual positions in the simulation ledger." actions={<EnvironmentBadge />} />
      <Panel title="Active simulation positions" subtitle={`${data.length} open · floating P/L +$440`}>
        <DataTable
          columns={["Ticket", "Symbol", "Direction", "Strategy", "Volume", "Entry", "Current", "SL", "TP", "P/L", "Pips", "Risk", "Duration", "Status", "Actions"]}
          rows={data.map((p) => [
            p.ticket,
            <strong key={`${p.id}-symbol`}>{p.symbol}</strong>,
            <DirectionBadge key={`${p.id}-direction`} value={p.direction} />,
            p.strategy,
            p.volume,
            p.entry,
            p.current,
            p.stopLoss,
            p.takeProfit,
            <PnLDisplay key={`${p.id}-pnl`} value={p.profit} />,
            p.pips,
            `${p.risk}%`,
            p.openedAt,
            <StatusBadge key={`${p.id}-status`} tone="good">
              OPEN
            </StatusBadge>,
            <div key={`${p.id}-actions`} className="row-actions">
              {["View", "Modify", "Partial Close", "Close"].map((a) => (
                <button key={a} onClick={() => setAction({ name: a, p })}>
                  {a}
                </button>
              ))}
            </div>,
          ])}
        />
      </Panel>
      <ConfirmationDialog open={!!action} title={`${action?.name} simulated position`} onCancel={() => setAction(null)} onConfirm={() => setAction(null)}>
        Apply “{action?.name}” to {action?.p.ticket}? This changes simulation state only.
      </ConfirmationDialog>
    </>
  );
}

export function Backtesting() {
  const initial: BacktestConfig = {
    strategy: "EMA Pullback",
    symbol: "XAUUSD",
    timeframe: "H1",
    startDate: "2025-01-01",
    endDate: "2025-12-31",
    initialBalance: 100000,
    risk: 1,
    spread: 1.8,
    commission: 7,
    slippage: 0.5,
  };
  const [result, setResult] = useState<Awaited<ReturnType<typeof services.backtest.run>>>();
  const [running, setRunning] = useState(false);
  const run = async () => {
    setRunning(true);
    setResult(await services.backtest.run(initial));
    setRunning(false);
  };
  return (
    <>
      <PageHeader title="Backtesting Lab" description="Run deterministic demonstrations; this is not a historical-market backtest." actions={<StatusBadge tone="info">DEMO ENGINE</StatusBadge>} />
      <Panel title="Backtest configuration" subtitle="Inputs are preserved for the future backend engine">
        <div className="form-grid">
          <Select id="backtest-strategy" label="Strategy">
            <option>EMA Pullback</option>
          </Select>
          <Select id="backtest-symbol" label="Symbol">
            <option>XAUUSD</option>
          </Select>
          <Select id="backtest-timeframe" label="Timeframe">
            <option>H1</option>
          </Select>
          {[
            ["Start date", "2025-01-01", "backtest-start-date"],
            ["End date", "2025-12-31", "backtest-end-date"],
            ["Initial balance", "100000", "backtest-initial-balance"],
            ["Risk %", "1", "backtest-risk"],
            ["Spread", "1.8", "backtest-spread"],
            ["Commission", "7", "backtest-commission"],
            ["Slippage", "0.5", "backtest-slippage"],
          ].map(([l, v, id]) => (
            <label key={id} className="field" htmlFor={id}>
              <span>{l}</span>
              <input id={id} name={id} value={v} readOnly />
            </label>
          ))}
        </div>
        <Button tone="primary" onClick={run} disabled={running}>
          <Play />
          {running ? "RUNNING DEMO…" : "RUN DEMO BACKTEST"}
        </Button>
      </Panel>
      {result && (
        <>
          <div className="metrics-grid">
            {Object.entries({
              "Net Profit": `$${result.netProfit.toLocaleString()}`,
              Return: `${result.returnPercent}%`,
              "Total Trades": result.totalTrades,
              "Win Rate": `${result.winRate}%`,
              "Profit Factor": result.profitFactor,
              "Max Drawdown": `${result.maxDrawdown}%`,
              Expectancy: `$${result.expectancy}`,
              Sharpe: result.sharpe,
            }).map(([l, v]) => (
              <MetricCard key={l} label={l} value={v} />
            ))}
          </div>
          <Panel title="Results workspace" subtitle="Overview · Trades · Monthly · Statistics">
            <div className="tabs">
              {["Overview", "Trades", "Monthly", "Statistics"].map((x) => (
                <button key={x}>{x}</button>
              ))}
            </div>
            <div className="backtest-charts">
              <EquityChart
                data={Array.from({ length: 12 }, (_, i) => ({
                  name: `M${i + 1}`,
                  value: 100000 + i * 1675 + Math.sin(i) * 1900,
                }))}
              />
              <div>
                <h3>Result detail</h3>
                {[
                  ["Winning trades", result.winningTrades],
                  ["Losing trades", result.losingTrades],
                  ["Average win", `$${result.averageWin}`],
                  ["Average loss", `-$${Math.abs(result.averageLoss)}`],
                  ["Recovery factor", result.recoveryFactor],
                ].map(([l, v]) => (
                  <p key={l}>
                    <span>{l}</span>
                    <strong>{v}</strong>
                  </p>
                ))}
              </div>
            </div>
          </Panel>
        </>
      )}
    </>
  );
}
