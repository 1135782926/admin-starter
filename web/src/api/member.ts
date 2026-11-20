import request from '@/utils/http'

export interface MemberGroup {
  id: number
  name: string
}

export type MemberGender = 'unknown' | 'male' | 'female'

export interface MemberRecord {
  id: number
  username: string
  nickname: string
  member_group_id: number
  group: MemberGroup
  avatar: string | null
  email: string | null
  mobile: string | null
  gender: MemberGender
  birthday: string | null
  signature: string | null
  status: boolean
  created_at: string | null
  updated_at: string | null
}

export interface MemberSearchParams {
  keyword?: string
  member_group_id?: number
  status?: boolean
  page?: number
  per_page?: number
}

export interface MemberInput {
  username: string
  nickname: string
  member_group_id: number
  password?: string
  avatar?: null
  email?: string | null
  mobile?: string | null
  gender?: MemberGender
  birthday?: string | null
  signature?: string | null
  status?: boolean
}

const membersUrl = '/api/v1/admin/members'
const groupsUrl = '/api/v1/admin/member-groups'

export function fetchGetMemberList(params: MemberSearchParams) {
  return request.get<Api.Common.PaginatedResponse<MemberRecord>>({
    url: membersUrl,
    params: {
      ...params,
      status: typeof params.status === 'boolean' ? Number(params.status) : undefined
    },
    showErrorMessage: false
  })
}

export function fetchGetMember(id: number) {
  return request.get<MemberRecord>({ url: `${membersUrl}/${id}`, showErrorMessage: false })
}

export function fetchCreateMember(data: MemberInput & { password: string }, avatar?: File) {
  return request.post<MemberRecord>({
    url: membersUrl,
    data: avatar ? memberFormData(data, avatar) : data,
    showErrorMessage: false
  })
}

export function fetchUpdateMember(id: number, data: Partial<MemberInput>, avatar?: File) {
  const url = `${membersUrl}/${id}`
  if (avatar) {
    const formData = memberFormData(data, avatar)
    formData.append('_method', 'PATCH')
    return request.post<MemberRecord>({ url, data: formData, showErrorMessage: false })
  }
  return request.patch<MemberRecord>({ url, data, showErrorMessage: false })
}

function memberFormData(data: Partial<MemberInput>, avatar: File) {
  const formData = new FormData()
  for (const [key, value] of Object.entries(data)) {
    if (key === 'avatar' || value === undefined) continue
    formData.append(key, typeof value === 'boolean' ? String(Number(value)) : String(value ?? ''))
  }
  formData.append('avatar', avatar)
  return formData
}

export function fetchGetMemberGroups() {
  return request.get<MemberGroup[]>({ url: groupsUrl, showErrorMessage: false })
}

export function fetchCreateMemberGroup(data: { name: string }) {
  return request.post<MemberGroup>({ url: groupsUrl, data, showErrorMessage: false })
}

export function fetchUpdateMemberGroup(id: number, data: { name: string }) {
  return request.patch<MemberGroup>({ url: `${groupsUrl}/${id}`, data, showErrorMessage: false })
}
