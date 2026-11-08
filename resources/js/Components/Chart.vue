<script setup>
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart as ChartJs,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

ChartJs.register(
    BarController,
    BarElement,
    CategoryScale,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

const props = defineProps({
    type: {
        type: String,
        default: 'line',
    },
    data: {
        type: Object,
        required: true,
    },
    options: {
        type: Object,
        default: () => ({}),
    },
    label: {
        type: String,
        default: null,
    },
});

const canvas = ref(null);
let chart = null;

function render() {
    if (chart) {
        chart.destroy();
        chart = null;
    }

    if (!canvas.value) {
        return;
    }

    chart = new ChartJs(canvas.value, {
        type: props.type,
        data: props.data,
        options: props.options,
    });
}

onMounted(render);

watch(() => [props.type, props.data, props.options], render);

onBeforeUnmount(() => {
    if (chart) {
        chart.destroy();
        chart = null;
    }
});
</script>

<template>
    <canvas ref="canvas" :aria-label="label" role="img"></canvas>
</template>
