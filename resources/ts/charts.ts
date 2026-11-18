import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    type ChartConfiguration,
    type ChartType,
} from 'chart.js';

Chart.register(
    ArcElement, BarController, BarElement, CategoryScale, DoughnutController, Filler,
    Legend, LinearScale, LineController, LineElement, PointElement, Tooltip,
);

/** UEC palette, readable on white; categorical order chosen for contrast. */
export const palette = {
    green: '#1d4536',
    greenSoft: 'rgba(29, 69, 54, 0.12)',
    gold: '#b8891c',
    teal: '#2f7d6d',
    clay: '#b4532a',
    slate: '#55606b',
    sky: '#3b78a8',
    grid: '#ece8dd',
    text: '#4b5a52',
} as const;

export const series = [palette.green, palette.gold, palette.teal, palette.clay, palette.sky, palette.slate];

export interface ChartPayload {
    type: 'line' | 'bar' | 'doughnut';
    labels: string[];
    datasets: Array<{ label: string; data: number[]; color?: string }>;
    money?: boolean;
    stacked?: boolean;
}

const naira = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 });
const compact = new Intl.NumberFormat('en', { notation: 'compact' });

Chart.defaults.font.family = "'Lato', system-ui, sans-serif";
Chart.defaults.color = palette.text;

export function buildConfig(payload: ChartPayload): ChartConfiguration<ChartType> {
    const isDoughnut = payload.type === 'doughnut';
    const format = (v: number): string => (payload.money ? naira.format(v) : compact.format(v));

    const datasets = payload.datasets.map((d, i) => {
        const color = d.color ?? series[i % series.length] ?? palette.green;
        if (isDoughnut) {
            return { label: d.label, data: d.data, backgroundColor: payload.labels.map((_, j) => series[j % series.length]), borderWidth: 2, borderColor: '#fff' };
        }
        return {
            label: d.label,
            data: d.data,
            borderColor: color,
            backgroundColor: payload.type === 'line' ? (i === 0 ? palette.greenSoft : 'transparent') : color,
            fill: payload.type === 'line' && i === 0,
            tension: 0.3,
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2,
            borderRadius: payload.type === 'bar' ? 4 : 0,
            maxBarThickness: 36,
        };
    });

    return {
        type: payload.type,
        data: { labels: payload.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: isDoughnut || payload.datasets.length > 1, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const value = typeof ctx.raw === 'number' ? ctx.raw : Number(ctx.raw);
                            return `${ctx.dataset.label ?? ctx.label}: ${payload.money ? naira.format(value) : value.toLocaleString()}`;
                        },
                    },
                },
            },
            scales: isDoughnut ? {} : {
                x: { stacked: payload.stacked ?? false, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                y: { stacked: payload.stacked ?? false, beginAtZero: true, grid: { color: palette.grid }, ticks: { callback: (v) => format(Number(v)), precision: 0 } },
            },
            ...(isDoughnut ? { cutout: '68%' } : {}),
        },
    } as ChartConfiguration<ChartType>;
}

export { Chart };
