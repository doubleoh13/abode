<script setup lang="ts" generic="TValue extends string | number">
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: TValue | null;
    options: Array<{ value: TValue; label: string }>;
    nullable?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [TValue | null] }>();

const open = ref(false);
const search = ref('');
const highlightedIndex = ref(0);
const inputElement = ref<HTMLInputElement | null>(null);

defineExpose({
    focus: (): void => inputElement.value?.focus(),
});

const selectedLabel = computed(
    () => props.options.find((option) => option.value === props.modelValue)?.label ?? '',
);

const displayValue = ref(selectedLabel.value);

watch(selectedLabel, (label) => {
    displayValue.value = label;
});

const filteredOptions = computed<Array<{ value: TValue | null; label: string }>>(() => {
    const query = search.value.trim().toLowerCase();

    if (query === '') {
        return props.nullable ? [{ value: null, label: '(none)' }, ...props.options] : props.options;
    }

    return props.options.filter((option) => option.label.toLowerCase().includes(query));
});

function openList(): void {
    open.value = true;
    search.value = '';
    highlightedIndex.value = Math.max(
        filteredOptions.value.findIndex((option) => option.value === props.modelValue),
        0,
    );
}

function handleInput(event: Event): void {
    displayValue.value = (event.target as HTMLInputElement).value;
    search.value = displayValue.value;
    open.value = true;
    highlightedIndex.value = 0;
}

function choose(option: { value: TValue | null; label: string }): void {
    emit('update:modelValue', option.value);
    displayValue.value = option.value === null ? '' : option.label;
    open.value = false;
}

function handleBlur(): void {
    open.value = false;

    if (props.nullable && displayValue.value.trim() === '') {
        emit('update:modelValue', null);
        return;
    }

    displayValue.value = selectedLabel.value;
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();

        if (!open.value) {
            openList();
            return;
        }

        const direction = event.key === 'ArrowDown' ? 1 : -1;
        const count = filteredOptions.value.length;
        highlightedIndex.value = (highlightedIndex.value + direction + count) % count;
    }

    if ((event.key === 'Enter' || event.key === 'Tab') && open.value) {
        if (event.key === 'Enter') {
            event.preventDefault();
        }

        const option = filteredOptions.value[highlightedIndex.value];

        if (option) {
            choose(option);
        }
    }

    if (event.key === 'Escape' && open.value) {
        event.stopPropagation();
        open.value = false;
        displayValue.value = selectedLabel.value;
    }
}
</script>

<template>
    <div class="relative">
        <input
            ref="inputElement"
            type="text"
            role="combobox"
            :aria-expanded="open"
            autocomplete="off"
            class="input w-full pr-8"
            :value="displayValue"
            @click="openList"
            @input="handleInput"
            @blur="handleBlur"
            @keydown="handleKeydown"
        />

        <svg
            class="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>

        <ul
            v-if="open && filteredOptions.length"
            role="listbox"
            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-sm border border-edge bg-surface py-1"
        >
            <li
                v-for="(option, index) in filteredOptions"
                :key="option.label"
                role="option"
                :aria-selected="option.value === modelValue"
                class="cursor-pointer px-3 py-1.5 text-sm"
                :class="index === highlightedIndex ? 'bg-background text-foreground' : 'text-muted'"
                @mousedown.prevent="choose(option)"
                @mousemove="highlightedIndex = index"
            >
                {{ option.label }}
            </li>
        </ul>
    </div>
</template>
