import { api, ApiError } from '@/api/client'

export async function uploadCatalogImage(file: File): Promise<string> {
  const formData = new FormData()
  formData.append('image', file)

  const res = await api.upload<{ data: { url: string } }>('/products/images/upload', formData)

  return res.data.url
}

export function catalogImageErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.body.errors?.image?.[0]) {
    return error.body.errors.image[0]
  }

  return error instanceof ApiError ? error.message : fallback
}
