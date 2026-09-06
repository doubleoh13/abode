<script setup lang="ts" generic="TValue extends string | number">
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: TValue | null;
    options: Array<{ value: TValue; label: string }>;
    nullable?: boolean;
    creatable?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [TValue | null];
    create: [string];
}>();

type Choice =
    | { type: 'option'; value: TValue | null; label: string }
    | { type: 'create'; query: string; label: string };

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

const choices = computed<Choice[]>(() => {
    const query = search.value.trim().toLowerCase();

    if (query === '') {
        const options: Choice[] = props.options.map((option) => ({ type: 'option', ...option }));

        return props.nullable
            ? [{ type: 'option', value: null, label: '(none)' }, ...options]
            : options;
    }

    const matchingOptions: Choice[] = props.options
        .filter((option) => option.label.toLowerCase().includes(query))
        .map((option) => ({ type: 'option', ...option }));
    const exactMatchExists = props.options.some(
        (option) => option.label.toLowerCase() === query,
    );

    if (props.creatable && !exactMatchExists) {
        matchingOptions.push({
            type: 'create',
            query: search.value.trim(),
            label: `Create “${search.value.trim()}”`,
        });
    }

    return matchingOptions;
});

function openList(): void {
    open.value = true;
    search.value = '';
    highlightedIndex.value = Math.max(
        choices.value.findIndex(
            (choice) => choice.type === 'option' && choice.value === props.modelValue,
        ),
        0,
    );
}

function handleInput(event: Event): void {
    displayValue.value = (event.target as HTMLInputElement).value;
    search.value = displayValue.value;
    open.value = true;
    highlightedIndex.value = 0;
}

function choose(choice: Choice): void {
    if (choice.type === 'create') {
        emit('create', choice.query);
        open.value = false;

        return;
    }

    emit('update:modelValue', choice.value);
    displayValue.value = choice.value === null ? '' : choice.label;
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
        const count = choices.value.length;

        if (count === 0) {
            return;
        }

        highlightedIndex.value = (highlightedIndex.value + direction + count) % count;
    }

    if ((event.key === 'Enter' || event.key === 'Tab') && open.value) {
        if (event.key === 'Enter') {
            event.preventDefault();
        }

        const choice = choices.value[highlightedIndex.value];

        if (choice) {
            choose(choice);
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
            aria-autocomplete="list"
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
            v-if="open && choices.length"
            role="listbox"
            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-sm border border-edge bg-surface py-1"
        >
            <li
                v-for="(choice, index) in choices"
                :key="choice.type === 'create' ? `create:${choice.query}` : `option:${choice.label}`"
                role="option"
                :aria-selected="choice.type === 'option' && choice.value === modelValue"
                class="cursor-pointer px-3 py-1.5 text-sm"
                :class="[
                    index === highlightedIndex ? 'bg-background text-foreground' : 'text-muted',
                    choice.type === 'create' ? 'font-medium' : '',
                    choice.type === 'create' && index > 0 ? 'border-t border-edge' : '',
                ]"
                @mousedown.prevent="choose(choice)"
                @mousemove="highlightedIndex = index"
            >
                {{ choice.label }}
            </li>
        </ul>
    </div>
</template>
