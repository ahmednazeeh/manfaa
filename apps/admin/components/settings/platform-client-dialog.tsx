'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  createPlatformClient,
  updatePlatformClient,
  type ConnectAbilityInfo,
  type PlatformClient,
  type PlatformClientSecretResponse,
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
import { Checkbox } from '@/components/ui/checkbox';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

const FormSchema = z.object({
  name: z.string().min(1, 'The name is required.').max(120),
  display_name: z.string().max(120),
  description: z.string().max(500),
  website: z.string().max(255),
  contact: z.string().max(255),
  /** One callback per line — exact matches, never prefixes. */
  redirect_uris: z.string(),
  allowed_abilities: z
    .array(z.string())
    .min(1, 'Tick at least one permission.'),
  connect_enabled: z.boolean(),
  /**
   * A plugin on merchants' own servers (WooCommerce): no secret, no
   * callback list — the callback arrives with each request and the
   * merchant's consent is the registration. Fixed at registration.
   */
  public_client: z.boolean(),
}).superRefine((values, ctx) => {
  if (values.public_client) return;

  const uris = splitUris(values.redirect_uris);

  if (uris.length === 0) {
    ctx.addIssue({
      code: 'custom',
      path: ['redirect_uris'],
      message: 'At least one callback URL is required.',
    });
  } else if (!uris.every((uri) => uri.startsWith('https://'))) {
    ctx.addIssue({
      code: 'custom',
      path: ['redirect_uris'],
      message: 'Every callback must be an absolute https:// URL.',
    });
  }
});

type FormValues = z.infer<typeof FormSchema>;

function splitUris(value: string): string[] {
  return value
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0);
}

/**
 * Register or edit a platform.
 *
 * The secret is generated server-side and shown exactly once. Everything
 * else is editable forever — including the ability ceiling, which is the
 * control that stops a platform approved for bookkeeping from later asking
 * shopkeepers for customer names.
 */
export function PlatformClientDialog({
  abilities,
  client,
  trigger,
}: {
  abilities: ConnectAbilityInfo[];
  client?: PlatformClient;
  trigger?: React.ReactNode;
}) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [created, setCreated] = useState<PlatformClientSecretResponse | null>(
    null,
  );
  const { isCopied, copyToClipboard } = useCopyToClipboard();
  const editing = client !== undefined;

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: defaults(client),
  });

  const save = useMutation({
    mutationFn: (values: FormValues) => {
      const body = {
        name: values.name,
        display_name: values.display_name || values.name,
        description: values.description || null,
        website: values.website || null,
        contact: values.contact || null,
        redirect_uris: values.public_client
          ? undefined
          : splitUris(values.redirect_uris),
        allowed_abilities: values.allowed_abilities,
        connect_enabled: values.connect_enabled,
      };

      return editing
        ? updatePlatformClient(client.id, body).then(() => null)
        : createPlatformClient({ ...body, public_client: values.public_client });
    },
    onSuccess: (response) => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'platform-clients'],
      });

      if (response === null) {
        toast.success('Platform updated.');
        setOpen(false);
        return;
      }

      setCreated(response);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const revealing = created !== null;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // While the one-time secret is on screen, only Done closes it.
        if (!next && revealing) {
          return;
        }
        setOpen(next);
        if (next) {
          form.reset(defaults(client));
          setCreated(null);
        }
      }}
    >
      <DialogTrigger asChild>
        {trigger ?? (
          <Button>
            <Plus />
            Register a platform
          </Button>
        )}
      </DialogTrigger>
      <DialogContent
        className="max-w-lg"
        showCloseButton={!revealing}
        onInteractOutside={(event) => revealing && event.preventDefault()}
        onEscapeKeyDown={(event) => revealing && event.preventDefault()}
      >
        {revealing ? (
          <>
            <DialogHeader>
              <DialogTitle>{created.data.name} registered</DialogTitle>
              <DialogDescription>
                {created.data.public_client
                  ? 'A public client has no secret — the client ID is all the plugin ships with.'
                  : 'Send both values to the developer over a secure channel.'}
              </DialogDescription>
            </DialogHeader>
            <DialogBody className="flex flex-col gap-4">
              <div className="flex flex-col gap-1.5">
                <span className="text-xs font-medium text-muted-foreground">
                  Client ID
                </span>
                <code className="overflow-x-auto rounded-md border border-border bg-muted/50 px-3 py-2.5 font-mono text-sm">
                  {created.data.client_id}
                </code>
              </div>
              {created.data.client_secret !== null ? (
                <div className="flex flex-col gap-1.5">
                  <span className="text-xs font-medium text-muted-foreground">
                    Client secret
                  </span>
                  <div className="flex items-center gap-2">
                    <code className="flex-1 overflow-x-auto rounded-md border border-border bg-muted/50 px-3 py-2.5 font-mono text-sm">
                      {created.data.client_secret}
                    </code>
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      aria-label="Copy client secret"
                      onClick={() =>
                        created.data.client_secret &&
                        copyToClipboard(created.data.client_secret)
                      }
                    >
                      {isCopied ? <Check className="text-success" /> : <Copy />}
                    </Button>
                  </div>
                </div>
              ) : null}
              <Alert variant="warning" appearance="light">
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>Shown once, never again</AlertTitle>
                  <AlertDescription>
                    Only a hash is kept. If it is lost, rotate — but rotating
                    cuts every merchant connection this platform already holds.
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
                Done — I have copied the secret
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>
                {editing ? `Edit ${client.name}` : 'Register a platform'}
              </DialogTitle>
              <DialogDescription>
                A registered platform can ask any merchant on Manfaa for access.
                The merchant still decides — but the asking is the privilege.
              </DialogDescription>
            </DialogHeader>
            <DialogBody className="max-h-[65vh] overflow-y-auto">
              <Form {...form}>
                <form
                  id="platform-client-form"
                  onSubmit={form.handleSubmit((values) => save.mutate(values))}
                  className="flex flex-col gap-4"
                >
                  <FormField
                    control={form.control}
                    name="name"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Platform name</FormLabel>
                        <FormControl>
                          <Input placeholder="IsleBooks" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="display_name"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Name shown to merchants</FormLabel>
                        <FormControl>
                          <Input placeholder="IsleBooks" {...field} />
                        </FormControl>
                        <FormDescription>
                          What the shopkeeper reads on the consent screen.
                          Defaults to the platform name.
                        </FormDescription>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="description"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>What it does</FormLabel>
                        <FormControl>
                          <Textarea
                            rows={2}
                            placeholder="Bookkeeping for Maldivian shops"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="website"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Website</FormLabel>
                        <FormControl>
                          <Input
                            placeholder="https://islebooks.mv"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="contact"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Developer contact</FormLabel>
                        <FormControl>
                          <Input placeholder="dev@islebooks.mv" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  {!editing ? (
                    <FormField
                      control={form.control}
                      name="public_client"
                      render={({ field }) => (
                        <FormItem className="flex flex-row items-start gap-3 rounded-md border border-border p-3">
                          <FormControl>
                            <Switch
                              checked={field.value}
                              onCheckedChange={field.onChange}
                            />
                          </FormControl>
                          <div className="flex flex-col gap-0.5">
                            <FormLabel>Public client (a plugin)</FormLabel>
                            <FormDescription>
                              For software that runs on the merchants&apos;
                              own servers and cannot keep a secret. No secret
                              is issued and no callback list is kept — each
                              store&apos;s callback is shown on the consent
                              screen and approved by its owner. Cannot be
                              changed later.
                            </FormDescription>
                          </div>
                        </FormItem>
                      )}
                    />
                  ) : null}
                  <FormField
                    control={form.control}
                    name="redirect_uris"
                    render={({ field }) => (
                      <FormItem
                        className={
                          form.watch('public_client') ? 'hidden' : undefined
                        }
                      >
                        <FormLabel>Callback URLs</FormLabel>
                        <FormControl>
                          <Textarea
                            rows={3}
                            className="font-mono text-xs"
                            placeholder="https://islebooks.mv/manfaa/callback"
                            {...field}
                          />
                        </FormControl>
                        <FormDescription>
                          One per line. Matched exactly, never by prefix, and
                          https only — an authorization code must never cross
                          the wire in clear.
                        </FormDescription>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="allowed_abilities"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>What it may ask merchants for</FormLabel>
                        <FormDescription>
                          The ceiling. A platform can never request more than is
                          ticked here, whatever its authorize URL says.
                        </FormDescription>
                        <div className="flex flex-col gap-2.5 pt-1">
                          {abilities.map((ability) => {
                            const checked = field.value.includes(
                              ability.ability,
                            );

                            return (
                              <label
                                key={ability.ability}
                                className="flex cursor-pointer items-start gap-2.5 rounded-md border border-border p-3 has-[:checked]:border-primary/40 has-[:checked]:bg-primary/5"
                              >
                                <Checkbox
                                  className="mt-0.5"
                                  checked={checked}
                                  onCheckedChange={(next) =>
                                    field.onChange(
                                      next === true
                                        ? [...field.value, ability.ability]
                                        : field.value.filter(
                                            (value) =>
                                              value !== ability.ability,
                                          ),
                                    )
                                  }
                                />
                                <span className="flex min-w-0 flex-col gap-0.5">
                                  <span className="text-sm font-medium">
                                    {ability.consent_line}
                                  </span>
                                  <span className="font-mono text-[11px] text-muted-foreground">
                                    {ability.ability}
                                  </span>
                                  {ability.caution ? (
                                    <span className="text-xs text-warning">
                                      {ability.caution}
                                    </span>
                                  ) : null}
                                </span>
                              </label>
                            );
                          })}
                        </div>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="connect_enabled"
                    render={({ field }) => (
                      <FormItem className="flex flex-row items-start gap-3 rounded-md border border-border p-3">
                        <FormControl>
                          <Switch
                            checked={field.value}
                            onCheckedChange={field.onChange}
                          />
                        </FormControl>
                        <div className="flex flex-col gap-0.5">
                          <FormLabel>Let it approach merchants</FormLabel>
                          <FormDescription>
                            Off keeps the registration but shuts the consent
                            screen — use it while an integration is still being
                            reviewed.
                          </FormDescription>
                        </div>
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
                form="platform-client-form"
                disabled={save.isPending}
              >
                {editing ? 'Save changes' : 'Register'}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

function defaults(client?: PlatformClient): FormValues {
  return {
    name: client?.name ?? '',
    display_name: client?.display_name ?? '',
    description: client?.description ?? '',
    website: client?.website ?? '',
    contact: client?.contact ?? '',
    redirect_uris: (client?.redirect_uris ?? []).join('\n'),
    allowed_abilities: client?.allowed_abilities ?? [],
    connect_enabled: client?.connect_enabled ?? false,
    public_client: client?.public_client ?? false,
  };
}
