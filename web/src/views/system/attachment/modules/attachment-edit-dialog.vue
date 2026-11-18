<template>
  <ElDialog
    v-model="visible"
    title="编辑附件"
    width="min(520px, calc(100% - 32px))"
    align-center
    destroy-on-close
    :close-on-click-modal="false"
    :close-on-press-escape="!saving"
    :show-close="!saving"
  >
    <ElAlert
      title="仅可修改名称和分类，不能更换文件或修改公开性。"
      type="info"
      :closable="false"
      class="mb-4"
    />
    <ElForm ref="formRef" :model="form" :rules="rules" label-width="90px" :disabled="saving">
      <ElFormItem label="附件名称" prop="title">
        <ElInput v-model="form.title" maxlength="255" />
      </ElFormItem>
      <ElFormItem label="分类">
        <ElInput v-model="form.category" maxlength="100" clearable placeholder="选填" />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton :disabled="saving" @click="visible = false">取消</ElButton>
      <ElButton type="primary" :loading="saving" @click="save">保存</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
  import { fetchUpdateAttachment } from '@/api/attachment'

  const props = defineProps<{ attachment?: Api.Attachment.Record }>()
  const visible = defineModel<boolean>('visible', { required: true })
  const emit = defineEmits<{ submit: [] }>()
  const formRef = ref<FormInstance>()
  const saving = ref(false)
  const form = reactive({ title: '', category: '' })
  const rules: FormRules = {
    title: [{ required: true, whitespace: true, message: '请输入附件名称', trigger: 'blur' }]
  }

  watch(visible, async (open) => {
    if (!open) return
    form.title = props.attachment?.title || ''
    form.category = props.attachment?.category || ''
    await nextTick()
    formRef.value?.clearValidate()
  })

  async function save() {
    if (!props.attachment || saving.value || !formRef.value) return
    if (!(await formRef.value.validate().catch(() => false))) return
    saving.value = true
    try {
      await fetchUpdateAttachment(props.attachment.id, {
        title: form.title.trim(),
        category: form.category.trim() || null
      })
      ElMessage.success('附件已保存')
      visible.value = false
      emit('submit')
    } catch {
      // 保存失败时保留输入，错误由 HTTP 统一提示。
    } finally {
      saving.value = false
    }
  }
</script>
