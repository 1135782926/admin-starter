import request from '@/utils/http'

export function fetchGetAttachmentList(params: Api.Attachment.SearchParams) {
  return request.get<Api.Attachment.List>({
    url: '/api/v1/admin/attachments',
    params
  })
}

export function fetchGetAttachment(id: number) {
  return request.get<Api.Attachment.Record>({ url: `/api/v1/admin/attachments/${id}` })
}

export function fetchUploadAttachment(
  file: File,
  data: { title?: string; category?: string; visibility?: Api.Attachment.Visibility }
) {
  const formData = new FormData()
  formData.append('file', file)
  if (data.title) formData.append('title', data.title)
  if (data.category) formData.append('category', data.category)
  formData.append('visibility', data.visibility || 'private')

  return request.post<Api.Attachment.Record>({
    url: '/api/v1/admin/attachments',
    data: formData,
    timeout: 120000,
    showErrorMessage: false
  })
}

export function fetchUpdateAttachment(id: number, data: Api.Attachment.UpdateInput) {
  return request.patch<Api.Attachment.Record>({
    url: `/api/v1/admin/attachments/${id}`,
    data
  })
}

export function fetchDeleteAttachment(id: number) {
  return request.del<null>({
    url: `/api/v1/admin/attachments/${id}`,
    data: { confirmed: true },
    showErrorMessage: false
  })
}

export function fetchGetAttachmentTemporaryUrl(
  id: number,
  disposition: Api.Attachment.Disposition
) {
  return request.get<Api.Attachment.TemporaryUrl>({
    url: `/api/v1/admin/attachments/${id}/temporary-url`,
    params: { disposition }
  })
}
