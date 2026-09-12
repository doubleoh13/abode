import { reactive } from 'vue';

interface PendingDialog {
    kind: 'confirm' | 'message';
    message: string;
    confirmLabel: string;
    resolve: (confirmed: boolean) => void;
}

export const dialog = reactive<{ pending: PendingDialog | null }>({ pending: null });

function open(pending: Omit<PendingDialog, 'resolve'>): Promise<boolean> {
    dialog.pending?.resolve(false);

    return new Promise((resolve) => {
        dialog.pending = { ...pending, resolve };
    });
}

export function settleDialog(confirmed: boolean): void {
    const pending = dialog.pending;

    dialog.pending = null;
    pending?.resolve(confirmed);
}

export function confirmAction(message: string, confirmLabel = 'Confirm'): Promise<boolean> {
    return open({ kind: 'confirm', message, confirmLabel });
}

export async function showMessage(message: string): Promise<void> {
    await open({ kind: 'message', message, confirmLabel: 'OK' });
}
