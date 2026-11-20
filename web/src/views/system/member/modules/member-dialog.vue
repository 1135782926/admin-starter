<template>
  <ElDialog
    v-model="visible"
    :title="memberId === undefined ? '新增会员' : '编辑会员'"
    width="min(760px, calc(100% - 32px))"
    class="flex max-h-[calc(100dvh-2rem)] flex-col"
    body-class="min-h-0 overflow-y-auto overscroll-contain"
    header-class="shrink-0"
    footer-class="shrink-0"
    align-center
    append-to-body
    destroy-on-close
    :close-on-click-modal="false"
    :close-on-press-escape="!saving"
    :show-close="!saving"
  >
    <div class="min-h-16" v-loading="loading" @submit.prevent="save">
      <ElAlert v-if="loadFailed" type="error" :closable="false" class="mb-3">
        <span>会员资料加载失败。</span>
        <ElButton link type="primary" @click="loadMember">重试</ElButton>
      </ElAlert>
      <ElAlert
        v-if="saveError"
        ref="saveAlert"
        :title="saveError.title"
        type="error"
        :closable="false"
      >
        <ul v-if="saveError.fields" class="m-0 list-disc pl-4">
          <li v-for="message in saveError.fields" :key="message">{{ message }}</li>
        </ul>
      </ElAlert>
      <ArtForm
        v-if="ready"
        ref="memberForm"
        v-model="form"
        :items="formItems"
        :rules="rules"
        :span="24"
        :gutter="0"
        :label-position="width < 480 ? 'top' : 'right'"
        label-width="86px"
        :disabled="saving || !canEdit"
        :show-reset="false"
        :show-submit="false"
        scroll-to-error
      >
        <template #member_group_id>
          <ElSelect
            v-model="form.member_group_id"
            filterable
            class="w-full"
            :loading="groupsLoading"
            :disabled="groupsLoading || groupsFailed"
            :placeholder="groupsLoading ? '正在加载会员分组…' : '请选择分组'"
          >
            <ElOption
              v-for="group in groups"
              :key="group.id"
              :label="group.name"
              :value="group.id"
            />
          </ElSelect>
          <div v-if="groupsFailed" class="flex items-center gap-2 text-sm text-error">
            <span>会员分组加载失败。</span>
            <ElButton link type="primary" @click="emit('retryGroups')">重试</ElButton>
          </div>
          <p v-else-if="!groupsLoading && !groups.length" class="m-0 text-sm text-g-500">
            暂无会员分组，请先创建分组。
          </p>
        </template>
        <template #avatar>
          <div>
            <ArtImageUpload
              v-model="avatarFile"
              :src="avatarRemoved ? null : currentMember?.avatar"
              :disabled="saving || !canEdit"
              :allow-library="canUseLibrary"
            />
            <ElButton v-if="avatarFile" link type="primary" @click="avatarFile = undefined">
              {{ currentMember?.avatar ? '取消更换' : '移除头像' }}
            </ElButton>
            <ElButton
              v-else-if="currentMember?.avatar"
              link
              type="primary"
              @click="avatarRemoved = !avatarRemoved"
            >
              {{ avatarRemoved ? '撤销移除' : '移除头像' }}
            </ElButton>
          </div>
        </template>
      </ArtForm>
    </div>
    <template #footer>
      <ElButton :disabled="saving" @click="visible = false">取消</ElButton>
      <ElButton type="primary" :loading="saving" :disabled="!canSave" @click="save">
        保存
      </ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { useWindowSize } from '@vueuse/core'
  import { ElMessage, type AlertInstance, type FormRules } from 'element-plus'
  import {
    fetchCreateMember,
    fetchGetMember,
    fetchUpdateMember,
    type MemberGender,
    type MemberGroup,
    type MemberInput,
    type MemberRecord
  } from '@/api/member'
  import ArtForm, { type FormItem } from '@/components/core/forms/art-form/index.vue'
  import ArtImageUpload from '@/components/core/forms/art-image-upload/index.vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import { isHttpError, type ErrorResponse } from '@/utils/http/error'

  interface MemberDraft {
    username?: string
    nickname?: string
    member_group_id?: number
    email?: string
    mobile?: string
    gender: MemberGender
    birthday?: string | null
    password?: string
    signature?: string
    status: boolean
  }

  const props = defineProps<{
    memberId?: number
    groups: MemberGroup[]
    groupsLoading: boolean
    groupsFailed: boolean
  }>()
  const visible = defineModel<boolean>('visible', { required: true })
  const emit = defineEmits<{ retryGroups: []; submit: [] }>()
  const { hasAuth } = useAuth()
  const { width } = useWindowSize()
  const userStore = useUserStore()
  const canUseLibrary = computed(() => userStore.info.buttons?.includes('system.attachment.view'))
  const memberForm = useTemplateRef<InstanceType<typeof ArtForm>>('memberForm')
  const saveAlert = useTemplateRef<AlertInstance>('saveAlert')
  const currentMember = shallowRef<MemberRecord>()
  const avatarFile = shallowRef<File>()
  const avatarRemoved = ref(false)
  const form = ref<MemberDraft>({ gender: 'unknown', status: true })
  const loading = ref(false)
  const loadFailed = ref(false)
  const saving = ref(false)
  const saveError = ref<{ title: string; fields?: string[] }>()
  const ready = computed(
    () => props.memberId === undefined || currentMember.value?.id === props.memberId
  )
  const canEdit = computed(() => hasAuth(props.memberId === undefined ? 'add' : 'edit'))
  const canSave = computed(
    () =>
      canEdit.value &&
      ready.value &&
      !loading.value &&
      !props.groupsLoading &&
      !props.groupsFailed &&
      props.groups.length > 0
  )
  let detailRequest = 0

  const rules = computed<FormRules<MemberDraft>>(() => ({
    username: [{ required: true, whitespace: true, message: '请输入用户名', trigger: 'blur' }],
    nickname: [{ required: true, whitespace: true, message: '请输入昵称', trigger: 'blur' }],
    member_group_id: [{ required: true, type: 'number', message: '请选择分组', trigger: 'change' }],
    email: [{ type: 'email', message: '请输入有效的电子邮箱', trigger: 'blur' }],
    password: [
      { required: props.memberId === undefined, message: '请输入密码', trigger: 'blur' },
      {
        validator: (_rule, value?: string) => {
          if (!value) return true
          if (Array.from(value).length < 8) return new Error('密码至少需要 8 个字符')
          if (new TextEncoder().encode(value).length > 72) return new Error('密码不能超过 72 字节')
          return true
        },
        trigger: 'blur'
      }
    ]
  }))

  const formItems = computed<FormItem[]>(() => [
    {
      label: '用户名',
      key: 'username',
      type: 'input',
      props: { maxlength: 64, autocomplete: 'off', placeholder: '请输入用户名（登录账号）' }
    },
    {
      label: '昵称',
      key: 'nickname',
      type: 'input',
      props: { maxlength: 100, placeholder: '请输入昵称' }
    },
    { label: '分组', key: 'member_group_id' },
    { label: '头像', key: 'avatar' },
    {
      label: '电子邮箱',
      key: 'email',
      type: 'input',
      props: { maxlength: 255, autocomplete: 'off', placeholder: '请输入电子邮箱' }
    },
    {
      label: '手机号',
      key: 'mobile',
      type: 'input',
      props: { maxlength: 32, type: 'tel', autocomplete: 'off', placeholder: '请输入手机号' }
    },
    {
      label: '性别',
      key: 'gender',
      type: 'radiogroup',
      props: {
        class: 'gap-2 [&_.el-radio]:mr-0',
        options: [
          { label: '未知', value: 'unknown', border: true },
          { label: '男', value: 'male', border: true },
          { label: '女', value: 'female', border: true }
        ]
      }
    },
    {
      label: '生日',
      key: 'birthday',
      type: 'date',
      props: {
        valueFormat: 'YYYY-MM-DD',
        placeholder: '请选择生日',
        clearable: true,
        class: '!w-full'
      }
    },
    {
      label: '密码',
      key: 'password',
      type: 'input',
      props: {
        type: 'password',
        showPassword: true,
        autocomplete: 'new-password',
        placeholder: props.memberId === undefined ? '请输入密码，至少 8 个字符' : '留空保留原密码'
      }
    },
    {
      label: '个性签名',
      key: 'signature',
      type: 'input',
      props: { type: 'textarea', rows: 2, maxlength: 500, placeholder: '请输入个性签名' }
    },
    {
      label: '状态',
      key: 'status',
      type: 'radiogroup',
      props: {
        class: 'gap-2 [&_.el-radio]:mr-0',
        options: [
          { label: '禁用', value: false, border: true },
          { label: '启用', value: true, border: true }
        ]
      }
    }
  ])

  watch(visible, (open) => {
    detailRequest++
    currentMember.value = undefined
    avatarFile.value = undefined
    avatarRemoved.value = false
    form.value = { gender: 'unknown', status: true }
    loading.value = false
    loadFailed.value = false
    saveError.value = undefined
    if (open && props.memberId !== undefined) void loadMember()
  })
  onBeforeUnmount(() => detailRequest++)

  async function loadMember() {
    if (props.memberId === undefined || loading.value) return
    const request = ++detailRequest
    loading.value = true
    loadFailed.value = false
    try {
      const member = await fetchGetMember(props.memberId)
      if (request !== detailRequest || !visible.value) return
      currentMember.value = member
      form.value = {
        username: member.username,
        nickname: member.nickname,
        member_group_id: member.member_group_id,
        email: member.email ?? '',
        mobile: member.mobile ?? '',
        gender: member.gender,
        birthday: member.birthday,
        signature: member.signature ?? '',
        status: member.status
      }
    } catch {
      if (request === detailRequest) loadFailed.value = true
    } finally {
      if (request === detailRequest) loading.value = false
    }
  }

  async function save() {
    if (!canSave.value || saving.value || !memberForm.value) return
    saving.value = true
    saveError.value = undefined
    try {
      const validated = await memberForm.value.validate()?.catch(() => false)
      const draft = form.value
      if (!validated || !visible.value || !canSave.value || draft.member_group_id === undefined) {
        return
      }
      const data: MemberInput = {
        username: draft.username?.trim() ?? '',
        nickname: draft.nickname?.trim() ?? '',
        member_group_id: draft.member_group_id,
        email: draft.email?.trim() || null,
        mobile: draft.mobile?.trim() || null,
        gender: draft.gender,
        birthday: draft.birthday || null,
        signature: draft.signature?.trim() || null,
        status: draft.status,
        ...(avatarRemoved.value && !avatarFile.value ? { avatar: null } : {}),
        ...(draft.password ? { password: draft.password } : {})
      }
      if (props.memberId === undefined) {
        await fetchCreateMember({ ...data, password: draft.password ?? '' }, avatarFile.value)
      } else {
        await fetchUpdateMember(props.memberId, data, avatarFile.value)
      }
      ElMessage.success('会员已保存')
      visible.value = false
      emit('submit')
    } catch (error) {
      const response = isHttpError(error) ? (error.data as ErrorResponse | undefined) : undefined
      const fields = Object.entries(response?.errors ?? {}).flatMap(([key, messages]) => {
        const label = formItems.value.find((item) => item.key === key)?.label
        return messages.map((message) =>
          typeof label === 'string' ? `${label}：${message}` : message
        )
      })
      saveError.value = fields.length
        ? { title: '请检查以下字段', fields }
        : { title: isHttpError(error) ? error.message : '会员保存失败，请重试。' }
      await nextTick()
      saveAlert.value?.$el.scrollIntoView({ block: 'nearest' })
    } finally {
      saving.value = false
    }
  }
</script>
