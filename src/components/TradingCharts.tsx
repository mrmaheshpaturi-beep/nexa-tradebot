import { useEffect, useRef } from 'react'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  CandlestickSeries,
  ColorType,
  CrosshairMode,
  LineSeries,
  createChart,
  type CandlestickData,
  type IChartApi,
  type LineData,
  type UTCTimestamp,
} from 'lightweight-charts'
import type { LegacyMockCandle } from '../domain/types'

const tooltipStyle = { background: '#111b2a', border: '1px solid #24344a', borderRadius: 8, fontSize: 12 }

export type ChartOverlaySeries = {
  id: string
  color: string
  points: { time: number; value: number }[]
}

export function EquityChart({ data }: { data: { name: string; value: number }[] }) {
  return <div className="chart-box"><ResponsiveContainer width="100%" height="100%"><AreaChart data={data}><defs><linearGradient id="equity" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#27d7a1" stopOpacity={.3} /><stop offset="95%" stopColor="#27d7a1" stopOpacity={0} /></linearGradient></defs><CartesianGrid stroke="#1d2a3b" vertical={false} /><XAxis dataKey="name" stroke="#64748b" fontSize={11} tickLine={false} /><YAxis stroke="#64748b" fontSize={11} tickFormatter={(v) => `$${Math.round(v / 1000)}k`} tickLine={false} /><Tooltip contentStyle={tooltipStyle} formatter={(v) => [`$${Number(v).toLocaleString()}`, 'Equity']} /><Area type="monotone" dataKey="value" stroke="#27d7a1" fill="url(#equity)" strokeWidth={2} /></AreaChart></ResponsiveContainer></div>
}
export function PerformanceChart() {
  const data = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'].map((name, i) => ({ name, profit: [820, -340, 1260, 640, 1842][i] }))
  return <div className="chart-box small"><ResponsiveContainer width="100%" height="100%"><BarChart data={data}><CartesianGrid stroke="#1d2a3b" vertical={false} /><XAxis dataKey="name" stroke="#64748b" fontSize={11} /><Tooltip contentStyle={tooltipStyle} /><Bar dataKey="profit" fill="#3794ff" radius={[3, 3, 0, 0]} /></BarChart></ResponsiveContainer></div>
}
export function CandlestickTerminal({
  candles,
  showGuides = true,
  label = 'XAUUSD simulated candlestick chart',
  overlays = [],
}: {
  candles: LegacyMockCandle[]
  showGuides?: boolean
  label?: string
  overlays?: ChartOverlaySeries[]
}) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!ref.current || !candles.length) return
    const chart: IChartApi = createChart(ref.current, {
      layout: { background: { type: ColorType.Solid, color: '#0b1320' }, textColor: '#788aa3' },
      grid: { vertLines: { color: '#172334' }, horzLines: { color: '#172334' } },
      crosshair: { mode: CrosshairMode.Normal },
      rightPriceScale: { borderColor: '#24344a' },
      timeScale: { borderColor: '#24344a', timeVisible: true },
      width: ref.current.clientWidth,
      height: 430,
    })
    const series = chart.addSeries(CandlestickSeries, {
      upColor: '#27d7a1',
      downColor: '#ff5e6c',
      borderVisible: false,
      wickUpColor: '#27d7a1',
      wickDownColor: '#ff5e6c',
    })
    series.setData(candles.map((c) => ({ ...c, time: c.time as UTCTimestamp })) as CandlestickData[])
    for (const overlay of overlays) {
      if (!overlay.points.length) continue
      const line = chart.addSeries(LineSeries, {
        color: overlay.color,
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: true,
        title: overlay.id,
      })
      line.setData(overlay.points.map((point) => ({
        time: point.time as UTCTimestamp,
        value: point.value,
      })) as LineData[])
    }
    if (showGuides) {
      series.createPriceLine({ price: 2642.2, color: '#27d7a1', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: 'SIM ENTRY' })
      series.createPriceLine({ price: 2631.4, color: '#ff5e6c', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: 'SL' })
      series.createPriceLine({ price: 2658.4, color: '#3794ff', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: 'TP1' })
    }
    chart.timeScale().fitContent()
    const resize = () => ref.current && chart.applyOptions({ width: ref.current.clientWidth })
    window.addEventListener('resize', resize)
    return () => { window.removeEventListener('resize', resize); chart.remove() }
  }, [candles, showGuides, overlays])
  return <div ref={ref} className="candlestick" aria-label={label} />
}
