<template>
  <ElDialog
    v-model="visible"
    :title="userData ? '编辑管理员' : '新增管理员'"
    width="min(640px, calc(100% - 32px))"
    align-center
    destroy-on-close
    :close-on-click-modal="false"
    :close-on-press-escape="!saving"
    :show-close="!saving"
  >
    <div v-loading="loading">
      <ElAlert
        v-if="isSelf"
        title="当前账号不能禁用或调整自己的角色组。"
        type="info"
        :closable="false"
        class="mb-4"
      />
      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        :disabled="loading || saving || !ready"
      >
        <ElFormItem label="用户名" prop="username">
          <ElInput v-model="form.username" maxlength="255" autocomplete="off" />
        </ElFormItem>
        <ElFormItem label="昵称" prop="nickname">
          <ElInput v-model="form.nickname" maxlength="255" />
        </ElFormItem>
        <ElFormItem label="密码" prop="password">
          <ElInput
            v-model="form.password"
            type="password"
            show-password
            autocomplete="new-password"
            maxlength="255"
            :placeholder="userData ? '留空保留原密码' : '至少 8 个字符'"
          />
          <p v-if="userData" class="text-xs text-g-500">修改密码后，该账号已有的登录会话将失效。</p>
        </ElFormItem>
        <ElFormItem label="邮箱" prop="email">
          <ElInput v-model="form.email" maxlength="255" />
        </ElFormItem>
        <ElFormItem label="手机号" prop="mobile">
          <ElInput v-model="form.mobile" maxlength="32" />
        </ElFormItem>
        <ElFormItem label="角色组" prop="role_ids">
          <ElSelect
            v-model="form.role_ids"
            multiple
            filterable
            class="w-full"
            :disabled="isSelf"
            placeholder="请选择可分配的角色组"
          >
            <ElOption
              v-for="role in roleOptions"
              :key="role.id"
              :value="role.id"
              :disabled="!role.can_assign"
              :label="`${role.display_name} (${role.name})${role.effective_status ? '' : ' · 已停用'}`"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="启用">
          <ElSwitch v-model="form.status" :disabled="isSelf" />
        </ElFormItem>
      </ElForm>
      <ElAlert
        v-if="visible && !loading && !ready"
        title="未能加载管理员资料，请关闭后重试。"
        type="error"
        :closable="false"
      />
    </div>
    <template #footer>
      <ElButton :disabled="saving" @click="visible = false">取消</ElButton>
      <ElButton
        v-if="canSave"
        type="primary"
        :loading="saving"
        :disabled="loading || !ready"
        @click="save"
        >保存</ElButton
      >
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
  import {
    fetchCreateAdmin,
    fetchGetAdmin,
    fetchGetRoleOptions,
    fetchUpdateAdmin
  } from '@/api/system-manage'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'

  const props = defineProps<{ userData?: Api.SystemManage.AdminRecord }>()
  const visible = defineModel<boolean>('visible', { required: true })
  const emit = defineEmits<{ submit: [] }>()
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const formRef = ref<FormInstance>()
  const roleOptions = ref<Api.SystemManage.RoleOption[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const ready = ref(false)
  const currentAdmin = ref<Api.SystemManage.AdminRecord>()
  const isSelf = computed(() => props.userData?.id === userStore.info.userId)
  const canSave = computed(() =>
    props.userData ? hasAuth('edit') && currentAdmin.value?.can_manage : hasAuth('add')
  )
  const form = reactive({
    username: '',
    nickname: '',
    password: '',
    email: '',
    mobile: '',
    status: true,
    role_ids: [] as number[]
  })
  let loadId = 0

  const rules = computed<FormRules>(() => ({
    username: [{ required: true, whitespace: true, message: '请输入用户名', trigger: 'blur' }],
    nickname: [{ required: true, whitespace: true, message: '请输入昵称', trigger: 'blur' }],
    password: [
      { required: !props.userData, message: '请输入密码', trigger: 'blur' },
      { min: 8, max: 255, message: '密码长度为 8 至 255 个字符', trigger: 'blur' }
    ],
    email: [{ type: 'email', message: '请输入有效邮箱', trigger: 'blur' }],
    role_ids: [
      { type: 'array', required: true, min: 1, message: '至少选择一个角色组', trigger: 'change' }
    ]
  }))

  watch(visible, async (open) => {
    const id = ++loadId
    ready.value = false
    if (!open) {
      form.password = ''
      return
    }
    loading.value = true
    currentAdmin.value = undefined
    roleOptions.value = []
    Object.assign(form, {
      username: '',
      nickname: '',
      password: '',
      email: '',
      mobile: '',
      status: true,
      role_ids: []
    })
    try {
      const [options, admin] = await Promise.all([
        fetchGetRoleOptions(),
        props.userData ? fetchGetAdmin(props.userData.id) : Promise.resolve(undefined)
      ])
      if (id !== loadId || !visible.value) return
      roleOptions.value = options
      currentAdmin.value = admin
      if (admin)
        Object.assign(form, {
          username: admin.username,
          nickname: admin.nickname ?? '',
          email: admin.email ?? '',
          mobile: admin.mobile ?? '',
          status: admin.status,
          role_ids: [...admin.role_ids]
        })
      ready.value = true
      await nextTick()
      formRef.value?.clearValidate()
    } catch {
      // HTTP 统一显示请求错误，并拒绝旧会话响应。
    } finally {
      if (id === loadId) loading.value = false
    }
  })

  async function save() {
    if (!canSave.value || !ready.value || saving.value || !formRef.value) return
    if (!(await formRef.value.validate().catch(() => false))) return
    saving.value = true
    try {
      const data: Api.SystemManage.AdminInput = {
        username: form.username.trim(),
        nickname: form.nickname.trim(),
        email: form.email.trim() || null,
        mobile: form.mobile.trim() || null,
        status: form.status,
        role_ids: [...form.role_ids],
        ...(form.password ? { password: form.password } : {})
      }
      if (props.userData) await fetchUpdateAdmin(props.userData.id, data)
      else await fetchCreateAdmin(data)
      ElMessage.success('管理员已保存')
      visible.value = false
      emit('submit')
    } catch {
      // 保存失败时保留输入，错误由 HTTP 统一提示。
    } finally {
      saving.value = false
    }
  }
</script>
