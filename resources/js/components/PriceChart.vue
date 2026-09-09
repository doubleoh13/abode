<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatAmount } from '../money';
import type { Commodity } from '../types';

const props = defineProps<{
    series: Array<[string, string]>;
    usd: Commodity;
}>();

const container = ref<HTMLElement | null>(null);
const width = ref(600);
const height = 180;
const padding = { top: 8, right: 8, bottom: 8, left: 8 };

let observer: ResizeObserver | null = null;

onMounted(() => {
    observer = new ResizeObserver((entries) => {
        width.value = Math.max(240, Math.floor(entries[0].contentRect.width));
    });

    if (container.value) {
        observer.observe(container.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

// Floats are fine here: chart geometry only, never stored values.
const values = computed(() => props.series.map(([, price]) => Number(price)));

const bounds = computed(() => {
    const min = Math.min(...values.value);
    const max = Math.max(...values.value);

    return max === min ? { min: min - 1, max: max + 1 } : { min, max };
});

function x(index: number): number {
    const span = Math.max(props.series.length - 1, 1);

    return padding.left + (index / span) * (width.value - padding.left - padding.right);
}

function y(value: number): number {
    const { min, max } = bounds.value;

    return padding.top + (1 - (value - min) / (max - min)) * (height - padding.top - padding.bottom);
}

const path = computed(() =>
    values.value.map((value, index) => `${index === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(value).toFixed(1)}`).join(' '),
);

const hovered = ref<number | null>(null);

function onMove(event: MouseEvent): void {
    const rect = container.value?.getBoundingClientRect();

    if (!rect || props.series.length === 0) {
        return;
    }

    const ratio = (event.clientX - rect.left - padding.left) / Math.max(rect.width - padding.left - padding.right, 1);
    hovered.value = Math.min(props.series.length - 1, Math.max(0, Math.round(ratio * (props.series.length - 1))));
}

const tooltip = computed(() => {
    if (hovered.value === null || props.series[hovered.value] === undefined) {
        return null;
    }

    const [date, price] = props.series[hovered.value];

    return {
        date,
        price: formatAmount(price, props.usd),
        x: x(hovered.value),
        y: y(values.value[hovered.value]),
        alignRight: x(hovered.value) > width.value / 2,
    };
});
</script>

<template>
    <div ref="container" class="relative" @mousemove="onMove" @mouseleave="hovered = null">
        <svg :width="width" :height="height" class="block">
            <line
                v-for="fraction in [0, 0.5, 1]"
                :key="fraction"
                :x1="padding.left"
                :x2="width - padding.right"
                :y1="padding.top + fraction * (height - padding.top - padding.bottom)"
                :y2="padding.top + fraction * (height - padding.top - padding.bottom)"
                class="stroke-edge"
                stroke-width="1"
            />
            <path :d="path" fill="none" class="stroke-accent" stroke-width="2" stroke-linejoin="round" />
            <template v-if="tooltip">
                <line :x1="tooltip.x" :x2="tooltip.x" :y1="padding.top" :y2="height - padding.bottom" class="stroke-muted" stroke-width="1" />
                <circle :cx="tooltip.x" :cy="tooltip.y" r="4" class="fill-accent stroke-background" stroke-width="2" />
            </template>
        </svg>

        <div
            v-if="tooltip"
            class="pointer-events-none absolute top-1 rounded-sm border border-edge bg-background px-2 py-1 font-mono text-xs"
            :style="tooltip.alignRight ? { right: `${width - tooltip.x + 8}px` } : { left: `${tooltip.x + 8}px` }"
        >
            <span class="text-muted">{{ tooltip.date }}</span> {{ tooltip.price }}
        </div>

        <div class="mt-1 flex justify-between font-mono text-xs text-muted">
            <span>{{ series[0]?.[0] }}</span>
            <span>
                {{ formatAmount(bounds.min.toFixed(2), usd) }} – {{ formatAmount(bounds.max.toFixed(2), usd) }}
            </span>
            <span>{{ series.at(-1)?.[0] }}</span>
        </div>
    </div>
</template>
