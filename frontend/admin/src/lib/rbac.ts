import type { AuthUser } from '@/api/client'

/** True when the signed-in user has any of the listed permissions. */
export function userHasPermission(
  user: AuthUser | null | undefined,
  permission: string | string[],
): boolean {
  if (!user?.permissions?.length) {
    return false
  }

  const needed = Array.isArray(permission) ? permission : [permission]
  return needed.some((p) => user.permissions.includes(p))
}
