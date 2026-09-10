<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{ modelValue: string }>();

const emit = defineEmits<{ 'update:modelValue': [string] }>();

const inputElement = ref<HTMLInputElement | null>(null);
const text = ref(props.modelValue);
const invalid = ref(false);

defineExpose({
    focus: (): void => inputElement.value?.focus(),
});

watch(
    () => props.modelValue,
    (value) => {
        if (value === '' && invalid.value) {
            return;
        }

        if (value !== parseDate(text.value)) {
            text.value = value;
            invalid.value = false;
        }
    },
);

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function toIso(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function fromIso(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function isRealDate(year: number, month: number, day: number): boolean {
    const candidate = new Date(year, month - 1, day);

    return candidate.getFullYear() === year && candidate.getMonth() === month - 1 && candidate.getDate() === day;
}

/**
 * Accepts YYYY-MM-DD, YYYYMMDD, M/D, M/D/YY, M/D/YYYY, or "t" for today.
 * Returns the ISO date, '' for blank, or null when the text is not a date.
 */
function parseDate(raw: string): string | null {
    const value = raw.trim().toLowerCase();

    if (value === '') {
        return '';
    }

    if (value === 't' || value === 'today') {
        return toIso(new Date());
    }

    let year: number;
    let month: number;
    let day: number;
    let match: RegExpMatchArray | null;

    if ((match = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/)) !== null) {
        [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    } else if ((match = value.match(/^(\d{4})(\d{2})(\d{2})$/)) !== null) {
        [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    } else if ((match = value.match(/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2}|\d{4}))?$/)) !== null) {
        month = Number(match[1]);
        day = Number(match[2]);
        year = match[3] === undefined ? new Date().getFullYear() : Number(match[3]);

        if (year < 100) {
            year += 2000;
        }
    } else {
        return null;
    }

    return isRealDate(year, month, day) ? `${year}-${pad(month)}-${pad(day)}` : null;
}

function commit(): void {
    const parsed = parseDate(text.value);

    invalid.value = parsed === null;
    inputElement.value?.setCustomValidity(parsed === null ? 'Enter a date as YYYY-MM-DD.' : '');

    if (parsed !== null) {
        text.value = parsed;

        if (parsed !== props.modelValue) {
            emit('update:modelValue', parsed);
        }
    } else if (props.modelValue !== '') {
        emit('update:modelValue', '');
    }
}

function handleInput(): void {
    if (/^\d{4}-\d{2}-\d{2}$/.test(text.value.trim())) {
        commit();
    } else if (invalid.value) {
        invalid.value = false;
        inputElement.value?.setCustomValidity('');
    }
}

function nudge(event: KeyboardEvent, direction: 1 | -1): void {
    event.preventDefault();

    const base = parseDate(text.value);
    const date = base === null || base === '' ? new Date() : fromIso(base);

    if (event.shiftKey) {
        const day = date.getDate();
        date.setDate(1);
        date.setMonth(date.getMonth() + direction);
        date.setDate(Math.min(day, new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()));
    } else {
        date.setDate(date.getDate() + direction);
    }

    text.value = toIso(date);
    commit();
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowUp') {
        nudge(event, 1);
    } else if (event.key === 'ArrowDown') {
        nudge(event, -1);
    } else if (event.key === 'Enter') {
        commit();
    }
}
</script>

<template>
    <input
        ref="inputElement"
        v-model="text"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        placeholder="YYYY-MM-DD"
        class="input font-mono"
        :class="{ 'border-danger': invalid }"
        title="Type a date. ↑/↓ moves a day, Shift+↑/↓ a month, T is today."
        @input="handleInput"
        @blur="commit"
        @keydown="handleKeydown"
    />
</template>
