<template>
  <ElDialog
    v-model="visible"
    title="分组管理"
    width="min(520px, calc(100% - 32px))"
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
    <div ref="editor" @submit.prevent="save">
      <ElAlert v-if="failed" type="error" :closable="false" class="mb-3">
        <span>会员分组加载失败。</span>
        <ElButton link type="primary" @click="emit('retry')">重试</ElButton>
      </ElAlert>
      <ElAlert v-if="saveError" :title="saveError" type="error" :closable="false" />
      <ArtForm
        ref="groupForm"
        v-model="form"
        :items="formItems"
        :rules="rules"
        :span="24"
        :gutter="0"
        label-position="top"
        :disabled="saving || loading || failed"
        :show-reset="false"
        :show-submit="false"
        scroll-to-error
      >
        <template #name>
          <ElInput
            ref="nameInput"
            v-model="form.name"
            :maxlength="100"
            :placeholder="editingId === undefined ? '请输入新分组名称' : '请输入修改后的分组名称'"
          />
        </template>
      </ArtForm>
    </div>
    <ArtTable
      :columns="columns"
      :data="groups"
      :loading="loading"
      height="auto"
      row-key="id"
      :empty-text="failed ? '会员分组加载失败' : '暂无会员分组'"
    >
      <template #operation="{ row }">
        <ArtButtonTable
          type="edit"
          role="button"
          :tabindex="saving || loading || failed ? -1 : 0"
          title="重命名分组"
          :aria-label="`重命名分组 ${row.name}`"
          :aria-disabled="saving || loading || failed"
          :class="{ 'pointer-events-none opacity-50': saving || loading || failed }"
          @click="rename(row)"
          @keydown.enter.prevent="rename(row)"
          @keydown.space.prevent="rename(row)"
        />
      </template>
    </ArtTable>
    <template #footer>
      <ElButton v-if="editingId !== undefined" :disabled="saving" @click="resetForm">
        取消重命名
      </ElButton>
      <ElButton v-else :disabled="saving" @click="visible = false">关闭</ElButton>
      <ElButton type="primary" :loading="saving" :disabled="loading || failed" @click="save">
        {{ editingId === undefined ? '新增分组' : '保存名称' }}
      </ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage, type FormRules, type InputInstance } from 'element-plus'
  import { fetchCreateMemberGroup, fetchUpdateMemberGroup, type MemberGroup } from '@/api/member'
  import ArtForm, { type FormItem } from '@/components/core/forms/art-form/index.vue'
  import type { ColumnOption } from '@/types'
  import { isHttpError } from '@/utils/http/error'

  const props = defineProps<{ groups: MemberGroup[]; loading: boolean; failed: boolean }>()
  const visible = defineModel<boolean>('visible', { required: true })
  const emit = defineEmits<{ retry: []; submit: [MemberGroup] }>()
  const groupForm = useTemplateRef<InstanceType<typeof ArtForm>>('groupForm')
  const editor = useTemplateRef<HTMLDivElement>('editor')
  const nameInput = useTemplateRef<InputInstance>('nameInput')
  const form = ref({ name: '' })
  const editingId = ref<number>()
  const saving = ref(false)
  const saveError = ref('')
  const formItems: FormItem[] = [{ label: '分组名称', key: 'name' }]
  const rules: FormRules = {
    name: [{ required: true, whitespace: true, message: '请输入分组名称', trigger: 'blur' }]
  }
  const columns: ColumnOption<MemberGroup>[] = [
    { prop: 'name', label: '分组', minWidth: 180, showOverflowTooltip: true },
    { prop: 'operation', label: '操作', width: 82, useSlot: true }
  ]

  watch(visible, (open) => {
    if (!open) resetForm()
  })

  function resetForm() {
    editingId.value = undefined
    form.value.name = ''
    saveError.value = ''
    void nextTick(() => groupForm.value?.ref?.clearValidate())
  }

  function rename(group: MemberGroup) {
    if (saving.value || props.loading || props.failed) return
    editingId.value = group.id
    form.value.name = group.name
    saveError.value = ''
    void nextTick(() => {
      groupForm.value?.ref?.clearValidate()
      nameInput.value?.focus()
    })
  }

  async function save() {
    if (saving.value || props.loading || props.failed || !groupForm.value) return
    saving.value = true
    saveError.value = ''
    try {
      const validated = await groupForm.value.validate()?.catch(() => false)
      if (!validated || !visible.value || props.loading || props.failed) return
      const data = { name: form.value.name.trim() }
      const group =
        editingId.value === undefined
          ? await fetchCreateMemberGroup(data)
          : await fetchUpdateMemberGroup(editingId.value, data)
      ElMessage.success(editingId.value === undefined ? '分组已新增' : '分组已重命名')
      resetForm()
      emit('submit', group)
    } catch (error) {
      saveError.value = isHttpError(error) ? error.message : '分组保存失败，请重试。'
      void nextTick(() => editor.value?.scrollIntoView({ block: 'start' }))
    } finally {
      saving.value = false
    }
  }
</script>
