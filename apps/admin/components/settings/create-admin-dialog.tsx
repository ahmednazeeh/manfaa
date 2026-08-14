'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  createAdminUser,
  type AdminRole,
  type CreateAdminUserResponse,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, Plus, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { apiErrorMessage } from '@/lib/api-error';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

const FormSchema = z.object({
  name: z
    .string()
    .min(1, 'The name is required.')
    .max(255, 'At most 255 characters.'),
  email: z
    .string()
    .min(1, 'The email is required.')
    .max(255, 'At most 255 characters.')
    .email('Enter a valid email address.'),
  role: z.enum(['admin', 'superadmin']),
});

type FormValues = z.infer<typeof FormSchema>;

/**
 * Creates an admin account. The server generates the temporary password and
 * returns it exactly once — only the hash survives — so after creation the
 * dialog locks open on a reveal step until the password is acknowledged.
 */
export function CreateAdminDialog() {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [created, setCreated] = useState<CreateAdminUserResponse | null>(null);
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: { name: '', email: '', role: 'admin' },
  });

  const create = useMutation({
    mutationFn: (values: FormValues) => createAdminUser(values),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'admin-users'] });
      setCreated(response);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const revealing = created !== null;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // While the one-time password is on screen, only the Done button
        // closes — an accidental overlay click would lose it forever.
        if (!next && revealing) {
          return;
        }
        setOpen(next);
        if (next) {
          form.reset();
          setCreated(null);
        }
      }}
    >
      <DialogTrigger asChild>
        <Button>
          <Plus />
          Add admin
        </Button>
      </DialogTrigger>
      <DialogContent
        className="max-w-md"
        showCloseButton={!revealing}
        onInteractOutside={(event) => {
          if (revealing) {
            event.preventDefault();
          }
        }}
        onEscapeKeyDown={(event) => {
          if (revealing) {
            event.preventDefault();
          }
        }}
      >
        {revealing ? (
          <>
            <DialogHeader>
              <DialogTitle>Account created</DialogTitle>
              <DialogDescription>
                {created.data.name} ({created.data.email}) can now sign in with
                this temporary password.
              </DialogDescription>
            </DialogHeader>
            <DialogBody className="flex flex-col gap-4">
              <div className="flex items-center gap-2">
                <code className="flex-1 overflow-x-auto rounded-md border border-border bg-muted/50 px-3 py-2.5 font-mono text-sm">
                  {created.temp_password}
                </code>
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  aria-label="Copy password"
                  onClick={() => copyToClipboard(created.temp_password)}
                >
                  {isCopied ? <Check className="text-success" /> : <Copy />}
                </Button>
              </div>
              <Alert variant="warning" appearance="light">
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>Shown once, never again</AlertTitle>
                  <AlertDescription>
                    Only a hash is stored server-side — this password cannot be
                    retrieved after you close this dialog. Pass it to{' '}
                    {created.data.name} over a secure channel and have them
                    change it after first sign-in.
                  </AlertDescription>
                </AlertContent>
              </Alert>
            </DialogBody>
            <DialogFooter>
              <Button
                type="button"
                onClick={() => {
                  setOpen(false);
                  setCreated(null);
                }}
              >
                Done — I have copied the password
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Add an admin</DialogTitle>
              <DialogDescription>
                The server generates a temporary password and shows it to you
                exactly once after creation.
              </DialogDescription>
            </DialogHeader>
            <DialogBody>
              <Form {...form}>
                <form
                  id="create-admin"
                  onSubmit={form.handleSubmit((values) =>
                    create.mutate(values),
                  )}
                  className="flex flex-col gap-4"
                >
                  <FormField
                    control={form.control}
                    name="name"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Name</FormLabel>
                        <FormControl>
                          <Input placeholder="Full name" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="email"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Email</FormLabel>
                        <FormControl>
                          <Input
                            type="email"
                            placeholder="name@example.com"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="role"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Role</FormLabel>
                        <Select
                          value={field.value}
                          onValueChange={(value) =>
                            field.onChange(value as AdminRole)
                          }
                        >
                          <FormControl>
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value="admin">Admin</SelectItem>
                            <SelectItem value="superadmin">
                              Superadmin
                            </SelectItem>
                          </SelectContent>
                        </Select>
                        <FormDescription>
                          Superadmins can additionally manage admin accounts.
                          Roles can be changed later.
                        </FormDescription>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </form>
              </Form>
            </DialogBody>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(false)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                form="create-admin"
                disabled={create.isPending}
              >
                {create.isPending ? 'Creating…' : 'Create account'}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
