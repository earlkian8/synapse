import { KeyRound, Printer, ShieldAlert } from 'lucide-react';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import type { IssuedPin } from '../types';

/**
 * The PINs just issued — shown this once, never again (ADR 0092). Each works
 * one time, for its holder to choose their own at a kiosk, so a slip that goes
 * astray is spent the moment its owner uses it. Printing makes one slip per
 * person, to cut and hand out.
 */
export function IssuedPinsDialog({
    issued,
    organization,
    onClose,
}: {
    issued: IssuedPin[];
    organization: string;
    onClose: () => void;
}) {
    return (
        <Modal
            open={issued.length > 0}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <KeyRound />
                        </ModalIcon>
                    }
                    title={
                        issued.length === 1
                            ? `${issued[0].full_name}’s PIN`
                            : `${issued.length} PINs`
                    }
                    description="Shown only now. Each works once, at any kiosk, for its holder to choose their own."
                />
                <ModalBody className="space-y-4">
                    <div className="flex items-start gap-2.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-sm">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <p>
                            Hand each PIN to its holder alone. Once you close
                            this, they can’t be shown again; you can only give a
                            new one.
                        </p>
                    </div>

                    <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                        {issued.map((item) => (
                            <li
                                key={item.employee_no}
                                className="flex items-center justify-between gap-4 px-3 py-2.5"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-medium">
                                        {item.full_name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {item.employee_no}
                                        {item.replaced &&
                                            ', replaces their old PIN'}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xl font-semibold tracking-[0.18em] tabular-nums">
                                    {item.pin}
                                </span>
                            </li>
                        ))}
                    </ul>
                </ModalBody>
                <ModalFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => printSlips(issued, organization)}
                    >
                        <Printer className="size-4" />
                        Print slips
                    </Button>
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

function escape(text: string): string {
    return text.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

/**
 * One slip per person, in a window of its own, so the app's own page is not
 * what gets printed. Nothing runs in that window: it is written and printed
 * from here, and closed after.
 */
function printSlips(issued: IssuedPin[], organization: string): void {
    const sheet = window.open('', '_blank', 'width=720,height=900');

    if (!sheet) {
        return;
    }

    const slips = issued
        .map(
            (item) => `
        <section class="slip">
            <p class="org">${escape(organization)}</p>
            <h2>${escape(item.full_name)}</h2>
            <dl>
                <div><dt>Employee number</dt><dd>${escape(item.employee_no)}</dd></div>
                <div><dt>First-time PIN</dt><dd class="pin">${escape(item.pin)}</dd></div>
            </dl>
            <p class="how">At the kiosk, type your employee number, then this PIN. You’ll choose a PIN of your own; this one stops working then. Keep your PIN to yourself.</p>
        </section>`,
        )
        .join('');

    sheet.document
        .write(`<!doctype html><html><head><meta charset="utf-8"><title>Kiosk PINs</title>
        <style>
            body { font-family: system-ui, sans-serif; margin: 24px; color: #0F2044; }
            .slip { border: 1px dashed #8a94a6; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px; break-inside: avoid; }
            .org { margin: 0; font-size: 12px; color: #566683; }
            h2 { margin: 4px 0 10px; font-size: 20px; }
            dl { display: flex; gap: 32px; margin: 0 0 10px; }
            dt { font-size: 11px; color: #566683; }
            dd { margin: 2px 0 0; font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
            .pin { letter-spacing: 0.2em; font-size: 24px; }
            .how { margin: 0; font-size: 12px; line-height: 1.45; max-width: 58ch; }
        </style></head><body>${slips}</body></html>`);
    sheet.document.close();
    sheet.focus();
    sheet.print();
    sheet.close();
}
