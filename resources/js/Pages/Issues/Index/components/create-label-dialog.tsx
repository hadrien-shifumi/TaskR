import { FormDialog } from '@/components/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';

type CreateLabelDialogProps = {
    open: boolean;
    onClose: () => void;
};

/** Workspace-wide label creation. */
export function CreateLabelDialog({ open, onClose }: CreateLabelDialogProps) {
    const labelForm = useForm({ name: '', color: '' });

    const submitLabel = (e: React.FormEvent) => {
        e.preventDefault();
        labelForm.post(route('labels.store'), {
            onSuccess: () => {
                onClose();
                labelForm.reset();
            },
        });
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title="New label"
            description="Workspace-wide labels, freely attachable."
            submitLabel="Create"
            processing={labelForm.processing}
            onSubmit={submitLabel}
        >
            <div className="grid gap-2">
                <Label htmlFor="label-name">Name</Label>
                <Input
                    id="label-name"
                    value={labelForm.data.name}
                    onChange={(e) => labelForm.setData('name', e.target.value)}
                    required
                />
                {labelForm.errors.name ? (
                    <p className="text-sm text-destructive">{labelForm.errors.name}</p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label htmlFor="label-color">Color</Label>
                <Input
                    id="label-color"
                    value={labelForm.data.color}
                    onChange={(e) => labelForm.setData('color', e.target.value)}
                    placeholder="gray"
                />
            </div>
        </FormDialog>
    );
}
