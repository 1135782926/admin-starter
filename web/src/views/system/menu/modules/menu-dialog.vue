<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="min(1100px, calc(100% - 32px))"
    align-center
    class="menu-dialog"
    @closed="handleClosed"
  >
    <ArtForm
      ref="formRef"
      v-model="form"
      :items="formItems"
      :rules="rules"
      :span="24"
      :gutter="16"
      label-width="110px"
      :show-reset="false"
      :show-submit="false"
    >
      <template #icon>
        <ArtIconPicker v-model="form.icon" :disabled="saving" />
      </template>
    </ArtForm>

    <template #footer>
      <span class="dialog-footer">
        <ElButton :disabled="saving" @click="handleCancel">取 消</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">保 存</ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import type { FormRules } from 'element-plus'
  import ArtForm, { type FormItem } from '@/components/core/forms/art-form/index.vue'
  import ArtIconPicker from '@/components/core/forms/art-icon-picker/index.vue'
  import {
    buildParentOptions,
    menuOpenTypeLabels,
    menuTypeLabels,
    type MenuNode,
    type MenuOption,
    type MenuType
  } from './menu-tree'

  type MenuInput = Api.SystemManage.MenuInput
  type MenuUpdateInput = Api.SystemManage.MenuUpdateInput
  type MenuOpenType = Api.SystemManage.MenuOpenType

  interface FormState {
    type: MenuType
    parent_id: number | null
    title: string
    name: string
    open_type: MenuOpenType
    path: string
    component: string
    url: string
    icon: string
    sort: number
    keep_alive: boolean
    status: boolean
    remark: string
    buttons: string[]
  }

  interface Props {
    visible: boolean
    mode: 'create' | 'edit'
    node: MenuNode | null
    parent: MenuNode | null
    allowedTypes: MenuType[]
    nodes: MenuNode[]
    saving?: boolean
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit', data: MenuInput | MenuUpdateInput): void
  }

  const props = withDefaults(defineProps<Props>(), {
    node: null,
    parent: null,
    saving: false
  })

  const emit = defineEmits<Emits>()
  const formRef = ref()

  const createForm = (): FormState => ({
    type: 'directory',
    parent_id: null,
    title: '',
    name: '',
    open_type: 'page',
    path: '',
    component: '',
    url: '',
    icon: '',
    sort: 1,
    keep_alive: false,
    status: true,
    remark: '',
    buttons: ['view', 'create', 'update', 'delete']
  })

  const form = reactive<FormState>(createForm())

  const dialogTitle = computed(() => {
    const action = props.mode === 'edit' ? '编辑' : '新建'
    return `${action}${menuTypeLabels[form.type]}`
  })

  const typeOptions = computed(() =>
    props.allowedTypes.map((value) => ({ value, label: menuTypeLabels[value], border: true }))
  )

  const openTypeOptions = Object.entries(menuOpenTypeLabels).map(([value, label]) => ({
    value,
    label,
    border: true
  }))

  const buttonOptions = [
    { value: 'view', label: '查看' },
    { value: 'create', label: '添加' },
    { value: 'update', label: '编辑' },
    { value: 'delete', label: '删除' }
  ]

  const statusOptions = [
    { value: false, label: '禁用', border: true },
    { value: true, label: '启用', border: true }
  ]

  const parentOptions = computed<MenuOption[]>(() =>
    buildParentOptions(props.nodes, props.mode === 'edit' ? props.node : null, form.type)
  )

  const treeSelectProps = {
    props: {
      label: 'title',
      children: 'children',
      value: 'id',
      disabled: 'disabled',
      emitPath: false,
      checkStrictly: true
    },
    clearable: true,
    class: '!w-full'
  }

  const rules = computed<FormRules>(() => ({
    type: [{ required: true, message: '请选择节点类型', trigger: 'change' }],
    title: [{ required: true, message: '请输入名称', trigger: 'blur' }],
    name: [
      { required: true, message: '请输入节点标识', trigger: 'blur' },
      {
        validator: (_rule: unknown, value: string, callback: (error?: Error) => void) => {
          if (!/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/.test(value || '')) {
            callback(new Error('仅允许字母、数字、下划线、短横线及点分隔的层级'))
            return
          }
          if (form.type === 'button') {
            const prefix = props.parent?.name
            if (!prefix || !value.startsWith(`${prefix}.`) || value === `${prefix}.access`) {
              callback(new Error('按钮标识必须位于所属菜单权限前缀下，且不能使用 access'))
              return
            }
          }
          callback()
        },
        trigger: 'blur'
      }
    ],
    open_type: [{ required: true, message: '请选择打开方式', trigger: 'change' }],
    path: [
      { required: true, message: '请输入路由地址', trigger: 'blur' },
      {
        validator: (_rule: unknown, value: string, callback: (error?: Error) => void) => {
          if (value && !/^\/(?!\/)[^?#\s]*$/.test(value)) {
            callback(new Error('路由地址必须以 / 开头且不能包含空格、? 或 #'))
            return
          }
          callback()
        },
        trigger: 'blur'
      }
    ],
    component: [{ required: true, message: '请输入组件路径', trigger: 'blur' }],
    url: [
      { required: true, message: '请输入链接地址', trigger: 'blur' },
      {
        validator: (_rule: unknown, value: string, callback: (error?: Error) => void) => {
          if (!value) {
            callback()
            return
          }
          try {
            const parsed = new URL(value)
            if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('invalid')
            callback()
          } catch {
            callback(new Error('链接必须是有效的 http 或 https 地址'))
          }
        },
        trigger: 'blur'
      }
    ],
    parent_id: [
      {
        validator: (_rule: unknown, value: number | null, callback: (error?: Error) => void) => {
          if (form.type === 'button' && value === null) {
            callback(new Error('按钮必须选择所属菜单'))
            return
          }
          callback()
        },
        trigger: 'change'
      }
    ]
  }))

  const formItems = computed<FormItem[]>(() => {
    const items: FormItem[] = [
      {
        label: '节点类型',
        key: 'type',
        type: 'radiogroup',
        props: {
          options: typeOptions.value,
          title: '目录归类菜单，菜单打开页面，按钮控制操作权限',
          disabled: props.mode === 'edit' || typeOptions.value.length === 1
        }
      },
      {
        label: '上级节点',
        key: 'parent_id',
        type: 'treeselect',
        props: {
          ...treeSelectProps,
          placeholder: form.type === 'button' ? '请选择所属菜单' : '请选择所属目录，留空为根节点',
          data: parentOptions.value,
          disabled: props.mode === 'edit' && form.type === 'button'
        }
      },
      {
        label: '名称',
        key: 'title',
        type: 'input',
        props: { maxlength: 255, placeholder: '请输入显示名称，如「会员管理」' }
      },
      {
        label: '节点标识',
        key: 'name',
        type: 'input',
        props: {
          maxlength: 248,
          disabled: props.mode === 'edit',
          placeholder:
            form.type === 'button'
              ? '请输入权限标识，如 system.member.export（创建后不可修改）'
              : '请输入唯一标识，如 system.member（创建后不可修改）'
        }
      }
    ]

    if (form.type !== 'button') {
      items.push({
        label: '路由地址',
        key: 'path',
        type: 'input',
        props: { maxlength: 255, placeholder: '请输入以 / 开头的路径，如 /system/member' }
      })
    }

    if (form.type === 'menu') {
      items.push({
        label: '打开方式',
        key: 'open_type',
        type: 'radiogroup',
        props: {
          options: openTypeOptions,
          disabled: props.mode === 'edit',
          title: '外部链接在新标签页打开，内嵌页面在后台打开'
        }
      })
      if (form.open_type === 'page') {
        items.push({
          label: '组件路径',
          key: 'component',
          type: 'input',
          props: {
            maxlength: 255,
            placeholder: '请输入 src/views 下的组件路径，如 /system/member/index（省略 .vue）'
          }
        })
        items.push({
          label: '页面缓存',
          key: 'keep_alive',
          type: 'radiogroup',
          props: { options: statusOptions, title: '启用后，切换页面再返回时保留页面状态' }
        })
      } else {
        items.push({
          label: '链接地址',
          key: 'url',
          type: 'input',
          props: { maxlength: 2048, placeholder: '请输入完整链接，如 https://example.com' }
        })
      }
      if (props.mode === 'create' && form.open_type === 'page') {
        items.push({
          label: '基础按钮',
          key: 'buttons',
          type: 'checkboxgroup',
          span: 24,
          props: { options: buttonOptions, title: '勾选创建菜单时一并生成的操作权限' }
        })
      }
    }

    if (form.type !== 'button') {
      items.push({ label: '图标', key: 'icon' })
    }

    items.push(
      {
        label: '排序',
        key: 'sort',
        type: 'number',
        props: {
          min: -2147483648,
          max: 2147483647,
          class: '!w-full',
          title: '数值越小，在同级节点中越靠前'
        }
      },
      {
        label: '状态',
        key: 'status',
        type: 'radiogroup',
        props: {
          options: statusOptions,
          title: form.type === 'button' ? '禁用后操作权限失效' : '禁用后此节点及下级不可访问'
        }
      },
      {
        label: '备注',
        key: 'remark',
        type: 'input',
        span: 24,
        props: {
          type: 'textarea',
          rows: 3,
          maxlength: 10000,
          placeholder: '可填写节点用途或维护说明'
        }
      }
    )

    return items
  })

  function loadForm(): void {
    const next = createForm()
    if (props.mode === 'edit' && props.node) {
      const node = props.node
      Object.assign(next, {
        type: node.type,
        parent_id: node.parent_id,
        title: node.title,
        name: node.name,
        open_type: node.open_type ?? 'page',
        path: node.path ?? '',
        component: node.component ?? '',
        url: node.url ?? '',
        icon: node.icon ?? '',
        sort: node.sort,
        keep_alive: node.keep_alive,
        status: node.status,
        remark: node.remark ?? '',
        buttons: []
      })
    } else {
      next.type = props.allowedTypes[0] ?? 'directory'
      next.parent_id = props.parent?.id ?? null
      if (props.parent) next.sort = (props.parent.children?.length ?? 0) + 1
    }
    Object.assign(form, next)
    void nextTick(() => formRef.value?.ref?.clearValidate())
  }

  function buildCreatePayload(): MenuInput {
    const base: MenuInput = {
      parent_id: form.parent_id,
      type: form.type,
      title: form.title.trim(),
      name: form.name.trim(),
      sort: form.sort,
      status: form.status,
      remark: form.remark.trim() || null
    }

    if (form.type === 'directory') {
      return { ...base, path: form.path.trim(), icon: form.icon.trim() || null }
    }
    if (form.type === 'button') return base
    if (form.open_type === 'page') {
      return {
        ...base,
        open_type: 'page',
        path: form.path.trim(),
        component: form.component.trim(),
        icon: form.icon.trim() || null,
        keep_alive: form.keep_alive,
        buttons: [...form.buttons]
      }
    }
    return {
      ...base,
      open_type: form.open_type,
      path: form.path.trim(),
      url: form.url.trim(),
      icon: form.icon.trim() || null
    }
  }

  function buildUpdatePayload(): MenuUpdateInput {
    const base: MenuUpdateInput = {
      title: form.title.trim(),
      sort: form.sort,
      status: form.status,
      remark: form.remark.trim() || null
    }

    if (form.type !== 'button') base.parent_id = form.parent_id
    if (form.type === 'directory') {
      return { ...base, path: form.path.trim(), icon: form.icon.trim() || null }
    }
    if (form.type === 'button') return base
    if (form.open_type === 'page') {
      return {
        ...base,
        open_type: 'page',
        path: form.path.trim(),
        component: form.component.trim(),
        icon: form.icon.trim() || null,
        keep_alive: form.keep_alive
      }
    }
    return {
      ...base,
      open_type: form.open_type,
      path: form.path.trim(),
      url: form.url.trim(),
      icon: form.icon.trim() || null
    }
  }

  async function handleSubmit(): Promise<void> {
    if (props.saving || !formRef.value) return
    try {
      await formRef.value.validate()
    } catch {
      return
    }

    emit('submit', props.mode === 'edit' ? buildUpdatePayload() : buildCreatePayload())
  }

  function handleCancel(): void {
    emit('update:visible', false)
  }

  function handleClosed(): void {
    Object.assign(form, createForm())
  }

  watch(
    () => props.visible,
    (visible) => {
      if (visible) loadForm()
    }
  )
</script>

<style>
  .menu-dialog {
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 40px);
  }

  .menu-dialog .el-dialog__header,
  .menu-dialog .el-dialog__footer {
    flex-shrink: 0;
  }

  .menu-dialog .el-dialog__body {
    min-height: 0;
    overflow-y: auto;
  }
</style>
