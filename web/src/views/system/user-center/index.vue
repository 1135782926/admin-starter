<template>
  <div class="grid gap-5 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    <section class="art-card-sm min-w-0 overflow-hidden" aria-label="个人资料">
      <div class="h-0.5 bg-[var(--el-color-primary)]" aria-hidden="true"></div>
      <ElSkeleton v-if="loading" :rows="8" animated class="p-5" />
      <template v-else-if="profile">
        <header class="flex flex-col items-center gap-2 px-5 pt-7 text-center">
          <button
            type="button"
            class="group relative cursor-pointer rounded-full disabled:cursor-not-allowed"
            aria-label="更换头像"
            title="更换头像"
            :disabled="pending !== null"
            @click="openAvatarDialog"
          >
            <ElAvatar :size="80" :src="avatarPreview || profile.avatar || undefined">
              <ArtSvgIcon icon="ri:user-3-fill" class="text-4xl" />
            </ElAvatar>
            <span
              class="absolute right-0 bottom-0 grid size-6 place-items-center rounded-full border-2 border-[var(--el-bg-color)] bg-[var(--el-color-primary)] text-xs text-white transition-opacity group-hover:opacity-80"
              aria-hidden="true"
            >
              <ArtSvgIcon icon="ri:edit-line" />
            </span>
          </button>
          <h1 class="max-w-full text-xl font-semibold wrap-break-word">{{ profile.nickname }}</h1>
          <p v-if="activity" class="text-sm text-g-500">
            <template v-if="activity.last_login_at">
              最近登录
              <time :datetime="activity.last_login_at">{{
                formatLogTime(activity.last_login_at)
              }}</time>
            </template>
            <template v-else>暂无登录记录</template>
          </p>
        </header>
        <ArtForm
          ref="profileFormRef"
          v-model="profileForm"
          :items="profileItems"
          :rules="profileRules"
          :span="24"
          :show-reset="false"
          :show-submit="false"
          :disabled="pending !== null"
          label-position="top"
        />
        <div class="flex flex-wrap items-center gap-2 px-4 pb-5">
          <ElButton
            type="primary"
            class="m-0!"
            :loading="pending === 'profile'"
            :disabled="pending === 'password'"
            @click="saveProfile"
          >
            保存修改
          </ElButton>
          <ElButton class="m-0!" :disabled="pending !== null" @click="resetProfile">重置</ElButton>
          <ElButton
            link
            type="primary"
            :disabled="pending !== null"
            @click="passwordVisible = true"
          >
            修改密码
          </ElButton>
        </div>
      </template>
      <ElEmpty v-else description="无法加载个人资料" :image-size="72">
        <ElButton @click="loadProfile">重新加载</ElButton>
      </ElEmpty>
    </section>

    <section class="art-card-sm flex min-w-0 flex-col" aria-labelledby="personal-activity-title">
      <h2
        id="personal-activity-title"
        class="border-b border-g-300 px-5 py-3.5 text-base font-medium"
      >
        操作日志
      </h2>
      <div v-loading="activityLoading" class="min-h-28 px-5 pt-4">
        <ElEmpty v-if="activityFailed" description="无法加载操作日志" :image-size="72">
          <ElButton @click="loadActivity(activity?.current)">重新加载</ElButton>
        </ElEmpty>
        <ElTimeline v-else-if="activity?.records.length" class="pl-1!">
          <ElTimelineItem
            v-for="log in activity.records"
            :key="log.id"
            :timestamp="formatLogTime(log.created_at)"
            placement="bottom"
            class="pb-3.5!"
          >
            <span class="text-sm">{{ operationLabel(log) }}</span>
            <span v-if="!log.success" class="ml-2 text-xs text-[var(--el-color-danger)]">失败</span>
          </ElTimelineItem>
        </ElTimeline>
        <ElEmpty v-else-if="!activityLoading" description="暂无操作日志" :image-size="72" />
      </div>
      <div
        v-if="activity?.total && !activityFailed"
        class="mt-auto border-t border-g-300 px-5 py-3"
      >
        <ElPagination
          small
          background
          layout="prev, next, jumper"
          :current-page="activity.current"
          :page-size="activity.size"
          :total="activity.total"
          :disabled="activityLoading"
          @update:current-page="loadActivity"
        />
      </div>
    </section>

    <ElDialog
      v-model="avatarVisible"
      title="更换头像"
      width="min(400px, calc(100% - 32px))"
      append-to-body
      destroy-on-close
      @closed="avatarDraft = undefined"
    >
      <ArtImageUpload
        v-model="avatarDraft"
        :src="profile?.avatar"
        :allow-library="userStore.info.buttons?.includes('system.attachment.view')"
      />
      <template #footer>
        <ElButton @click="avatarVisible = false">取消</ElButton>
        <ElButton type="primary" :disabled="!avatarDraft" @click="applyAvatar">使用此图片</ElButton>
      </template>
    </ElDialog>

    <ElDialog
      v-model="passwordVisible"
      title="修改密码"
      width="min(460px, calc(100% - 32px))"
      append-to-body
      destroy-on-close
      :close-on-click-modal="false"
      :close-on-press-escape="!pending"
      :show-close="!pending"
      @closed="clearPasswordForm"
    >
      <p class="px-4 text-sm text-g-500">修改后，所有设备都需要重新登录。</p>
      <ArtForm
        ref="passwordFormRef"
        v-model="passwordForm"
        :items="passwordItems"
        :rules="passwordRules"
        :span="24"
        :show-reset="false"
        :show-submit="false"
        :disabled="pending !== null"
        label-position="top"
      />
      <template #footer>
        <ElButton :disabled="pending !== null" @click="passwordVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="pending === 'password'" @click="changePassword">
          保存新密码
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, type FormRules } from 'element-plus'
  import {
    fetchChangePassword,
    fetchGetPersonalLogs,
    fetchGetProfile,
    fetchUpdateProfile
  } from '@/api/auth'
  import ArtForm, { type FormItem } from '@/components/core/forms/art-form/index.vue'
  import ArtImageUpload from '@/components/core/forms/art-image-upload/index.vue'
  import { useUserStore } from '@/store/modules/user'
  import { actionLabels, formatLogTime, moduleLabels } from '../log/modules/log-display'

  defineOptions({ name: 'UserCenter' })

  const userStore = useUserStore()
  const profile = shallowRef<Api.Auth.Profile>()
  const activity = shallowRef<Api.Auth.PersonalLogList>()
  const activityLoading = ref(true)
  const activityFailed = ref(false)
  let activityRequest = 0
  const loading = ref(true)
  const pending = ref<'profile' | 'password' | null>(null)
  const avatarFile = shallowRef<File>()
  const avatarDraft = shallowRef<File>()
  const avatarPreview = useObjectUrl(avatarFile)
  const avatarVisible = ref(false)
  const passwordVisible = ref(false)
  const profileFormRef = useTemplateRef<InstanceType<typeof ArtForm>>('profileFormRef')
  const passwordFormRef = useTemplateRef<InstanceType<typeof ArtForm>>('passwordFormRef')

  const profileForm = ref({
    username: '',
    roles: '',
    nickname: '',
    email: '',
    mobile: '',
    signature: ''
  })
  const passwordForm = ref<Api.Auth.ChangePasswordInput>({
    current_password: '',
    password: '',
    password_confirmation: ''
  })

  const profileItems: FormItem[] = [
    { label: '用户名', key: 'username', type: 'input', props: { disabled: true } },
    { label: '角色组', key: 'roles', type: 'input', props: { disabled: true } },
    {
      label: '昵称',
      key: 'nickname',
      type: 'input',
      props: { maxlength: 255, autocomplete: 'nickname' }
    },
    {
      label: '邮箱',
      key: 'email',
      type: 'input',
      props: { maxlength: 255, autocomplete: 'email' }
    },
    {
      label: '手机号',
      key: 'mobile',
      type: 'input',
      props: { maxlength: 32, type: 'tel', autocomplete: 'tel' }
    },
    {
      label: '个性签名',
      key: 'signature',
      type: 'input',
      props: { type: 'textarea', rows: 3, maxlength: 255 }
    }
  ]

  const passwordItems: FormItem[] = [
    {
      label: '当前密码',
      key: 'current_password',
      type: 'input',
      props: { type: 'password', showPassword: true, autocomplete: 'current-password' }
    },
    {
      label: '新密码',
      key: 'password',
      type: 'input',
      props: { type: 'password', showPassword: true, autocomplete: 'new-password', maxlength: 255 }
    },
    {
      label: '确认新密码',
      key: 'password_confirmation',
      type: 'input',
      props: { type: 'password', showPassword: true, autocomplete: 'new-password', maxlength: 255 }
    }
  ]

  const profileRules: FormRules = {
    nickname: [{ required: true, whitespace: true, message: '请输入昵称', trigger: 'blur' }],
    email: [{ type: 'email', message: '请输入有效邮箱', trigger: 'blur' }]
  }

  const passwordRules: FormRules = {
    current_password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
    password: [
      { required: true, message: '请输入新密码', trigger: 'blur' },
      { min: 8, message: '新密码至少需要 8 个字符', trigger: 'blur' }
    ],
    password_confirmation: [
      { required: true, message: '请确认新密码', trigger: 'blur' },
      {
        validator: (_rule, value: string) => value === passwordForm.value.password,
        message: '两次输入的新密码不一致',
        trigger: 'blur'
      }
    ]
  }

  function applyProfile(data: Api.Auth.Profile) {
    profile.value = data
    profileForm.value = {
      username: data.username,
      roles: userStore.info.roles?.join('、') || '未分配',
      nickname: data.nickname,
      email: data.email ?? '',
      mobile: data.mobile ?? '',
      signature: data.signature ?? ''
    }
    avatarFile.value = undefined
    userStore.info.email = data.email ?? ''
    userStore.info.avatar = data.avatar ?? undefined
  }

  function resetProfile() {
    if (!profile.value || pending.value) return
    applyProfile(profile.value)
    void nextTick(() => profileFormRef.value?.ref?.clearValidate())
  }

  function openAvatarDialog() {
    avatarDraft.value = avatarFile.value
    avatarVisible.value = true
  }

  function applyAvatar() {
    avatarFile.value = avatarDraft.value
    avatarVisible.value = false
  }

  function operationLabel(log: Api.Auth.PersonalLogRecord) {
    const action = actionLabels[log.action] || log.action
    if (log.module === 'auth' || log.action === 'change_password') return action
    if (log.module === 'profile' && log.action === 'update') return '修改个人资料'
    if (log.module === 'attachment' && log.action === 'create') return '上传附件'
    return `${log.action === 'update' ? '修改' : action}${moduleLabels[log.module] || log.module}`
  }

  async function loadActivity(page = 1) {
    const request = ++activityRequest
    const session = userStore.getSessionVersion()
    activityLoading.value = true
    activityFailed.value = false
    try {
      const data = await fetchGetPersonalLogs(page)
      if (request === activityRequest && session === userStore.getSessionVersion())
        activity.value = data
    } catch {
      if (request === activityRequest && session === userStore.getSessionVersion())
        activityFailed.value = true
    } finally {
      if (request === activityRequest) activityLoading.value = false
    }
  }

  async function loadProfile() {
    loading.value = true
    const session = userStore.getSessionVersion()
    try {
      const data = await fetchGetProfile()
      if (session === userStore.getSessionVersion()) applyProfile(data)
    } catch {
      // 请求错误由 HTTP 客户端提示。
    } finally {
      loading.value = false
    }
  }

  async function saveProfile() {
    if (pending.value || !profileFormRef.value) return
    const session = userStore.getSessionVersion()
    pending.value = 'profile'
    try {
      if (!(await profileFormRef.value.validate()) || session !== userStore.getSessionVersion())
        return
      const { nickname, email, mobile, signature } = profileForm.value
      const data = await fetchUpdateProfile(
        {
          nickname: nickname.trim(),
          email: email.trim() || null,
          mobile: mobile.trim() || null,
          signature: signature.trim() || null
        },
        avatarFile.value
      )
      if (session !== userStore.getSessionVersion()) return
      applyProfile(data)
      ElMessage.success('个人资料已保存')
      void loadActivity()
    } catch {
      // 保留表单输入以便修改后重试。
    } finally {
      pending.value = null
    }
  }

  function clearPasswordForm() {
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' }
  }

  async function changePassword() {
    if (pending.value || !passwordFormRef.value) return
    const session = userStore.getSessionVersion()
    pending.value = 'password'
    try {
      if (!(await passwordFormRef.value.validate()) || session !== userStore.getSessionVersion())
        return
      await fetchChangePassword(passwordForm.value)
      if (session !== userStore.getSessionVersion()) return
      clearPasswordForm()
      ElMessage.success('密码已修改，请重新登录')
      userStore.logOut()
    } catch {
      // 表单校验和 HTTP 客户端分别提示错误。
    } finally {
      pending.value = null
    }
  }

  onMounted(() => {
    void loadProfile()
    void loadActivity()
  })
</script>
