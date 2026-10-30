import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index, update } from '@/routes/admin/roles';
import type { PermissionOption, RoleWithPermissions } from '@/types/admin';

type Props = {
    roles: RoleWithPermissions[];
    modules: Record<string, string>;
    permissions: PermissionOption[];
    isSuperAdmin: boolean;
};

const TECHNICAL_PERMISSIONS = [
    'admin.system.manage',
    'admin.users.impersonate',
];

export default function AdminRolesIndex({
    roles,
    modules,
    permissions,
    isSuperAdmin,
}: Props) {
    const [selected, setSelected] = useState<Record<string, string[]>>(() =>
        Object.fromEntries(roles.map((role) => [role.name, role.permissions])),
    );

    const toggle = (
        roleName: string,
        permissionName: string,
        checked: boolean,
    ) =>
        setSelected((previous) => ({
            ...previous,
            [roleName]: checked
                ? [...previous[roleName], permissionName]
                : previous[roleName].filter((name) => name !== permissionName),
        }));

    return (
        <>
            <Head title="Roles y permisos" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Roles y permisos" />
                <Tabs defaultValue={roles[0].name}>
                    <TabsList>
                        {roles.map((role) => (
                            <TabsTrigger key={role.name} value={role.name}>
                                {role.label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {roles.map((role) => (
                        <TabsContent
                            key={role.name}
                            value={role.name}
                            className="flex flex-col gap-4"
                        >
                            {Object.entries(modules).map(
                                ([moduleKey, moduleLabel]) => (
                                    <Card key={moduleKey}>
                                        <CardHeader>
                                            <CardTitle>{moduleLabel}</CardTitle>
                                        </CardHeader>
                                        <CardContent className="grid gap-3 md:grid-cols-2">
                                            {permissions
                                                .filter(
                                                    (permission) =>
                                                        permission.module ===
                                                        moduleKey,
                                                )
                                                .map((permission) => {
                                                    const disabled =
                                                        role.name ===
                                                            'super-admin' ||
                                                        (!isSuperAdmin &&
                                                            TECHNICAL_PERMISSIONS.includes(
                                                                permission.name,
                                                            ));

                                                    return (
                                                        <div
                                                            key={
                                                                permission.name
                                                            }
                                                            className="flex items-center gap-2"
                                                        >
                                                            <Checkbox
                                                                id={`${role.name}-${permission.name}`}
                                                                checked={selected[
                                                                    role.name
                                                                ].includes(
                                                                    permission.name,
                                                                )}
                                                                disabled={
                                                                    disabled
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    toggle(
                                                                        role.name,
                                                                        permission.name,
                                                                        checked ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                            <Label
                                                                htmlFor={`${role.name}-${permission.name}`}
                                                            >
                                                                {
                                                                    permission.label
                                                                }
                                                            </Label>
                                                        </div>
                                                    );
                                                })}
                                        </CardContent>
                                    </Card>
                                ),
                            )}
                            {role.name === 'super-admin' ? (
                                <p className="text-sm text-muted-foreground">
                                    El Super Admin tiene todos los permisos y no
                                    se puede modificar.
                                </p>
                            ) : (
                                <div>
                                    <Button
                                        onClick={() =>
                                            router.put(update.url(role.name), {
                                                permissions:
                                                    selected[role.name],
                                            })
                                        }
                                    >
                                        Guardar
                                    </Button>
                                </div>
                            )}
                        </TabsContent>
                    ))}
                </Tabs>
            </div>
        </>
    );
}

AdminRolesIndex.layout = {
    breadcrumbs: [{ title: 'Roles y permisos', href: index() }],
};
