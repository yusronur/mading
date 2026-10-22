import Sortable from 'sortablejs';

/**
 * Direktif v-sortable: geser kartu/kolom dengan SortableJS (mendukung sentuh).
 * DOM dikembalikan ke posisi semula setelah digeser; perubahan urutan
 * dilakukan lewat data (onMove) agar Vue tetap memegang kendali DOM.
 *
 * Nilai: { group, handle, disabled, onMove({ id, fromKey, toKey, newIndex }) }
 */
export const vSortable = {
    mounted(el, binding) {
        const opts = binding.value || {};
        el._sortable = Sortable.create(el, {
            group: opts.group,
            handle: opts.handle,
            draggable: opts.draggable || '[data-id]',
            disabled: !!opts.disabled,
            animation: 160,
            delay: 180,
            delayOnTouchOnly: true,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            // Mode fallback: perilaku sama di mouse dan layar sentuh
            forceFallback: true,
            fallbackTolerance: 4,
            fallbackOnBody: true,
            onEnd(evt) {
                const { item, from, to, oldIndex, newIndex } = evt;
                if (from === to && oldIndex === newIndex) return;

                // Kembalikan DOM; Vue yang akan menyusun ulang dari data
                to.removeChild(item);
                const ref = [...from.children].filter((c) => c.matches(el._sortable.options.draggable))[oldIndex] ?? null;
                from.insertBefore(item, ref);

                el._sortableValue?.onMove?.({
                    id: item.dataset.id,
                    fromKey: from.dataset.key ?? null,
                    toKey: to.dataset.key ?? null,
                    newIndex: evt.newDraggableIndex ?? newIndex,
                });
            },
        });
        el._sortableValue = opts;
    },
    updated(el, binding) {
        el._sortableValue = binding.value || {};
        el._sortable?.option('disabled', !!binding.value?.disabled);
    },
    unmounted(el) {
        el._sortable?.destroy();
    },
};
