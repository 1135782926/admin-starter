<template>
  <ElDialog
    v-model="visible"
    :title="roleData ? (readOnly ? '查看角色组（只读）' : '编辑角色组') : '新增角色组'"
    width="min(1200px, calc(100% - 32px))"
    class="role-dialog"
    align-center
    destroy-on-close
    :close-on-click-modal="false"
    :close-on-press-escape="!saving"
    :show-close="!saving"
  >
    <div v-loading="loading">
      <ElAlert
        v-if="roleData?.name === 'super-admin'"
        title="超级管理员组为保留角色，只能查看。"
        type="info"
        :closable="false"
        class="mb-4"
      />
      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        :disabled="readOnly || loading || saving || !ready"
      >
        <ElFormItem label="上级分组" prop="parent_id">
          <ElSelect
            v-model="form.parent_id"
            filterable
            class="w-full"
            placeholder="点击选择"
            @change="changeParent"
          >
            <ElOption
              v-if="isSuper || (readOnly && roleData?.parent_id === null)"
              label="顶级角色组"
              :value="0"
            />
            <ElOption
              v-for="role in parentOptions"
              :key="role.id"
              :label="`${role.display_name} (${role.name})`"
              :value="role.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="角色编码" prop="name">
          <ElInput
            v-model="form.name"
            :disabled="!!roleData"
            maxlength="255"
            placeholder="请输入角色编码，创建后不可修改"
          />
        </ElFormItem>
        <ElFormItem label="组别名称" prop="display_name">
          <ElInput v-model="form.display_name" maxlength="255" placeholder="请输入组别名称" />
        </ElFormItem>
        <ElFormItem label="权限">
          <div class="w-full">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
              <span class="text-sm text-g-600">已选 {{ selectedIds.length }} 项</span>
              <ElSpace wrap>
                <ElButton size="small" :disabled="loading || !ready" @click="toggleExpand">
                  {{ expanded ? '全部收起' : '全部展开' }}
                </ElButton>
                <ElButton
                  v-if="!readOnly"
                  size="small"
                  :disabled="loading || saving || !ready"
                  @click="selectAll"
                >
                  全选可分配权限
                </ElButton>
                <ElButton
                  v-if="!readOnly"
                  size="small"
                  :disabled="loading || saving || !ready"
                  @click="setSelection([])"
                >
                  清空
                </ElButton>
              </ElSpace>
            </div>
            <p class="mb-2 text-xs text-g-500">
              子组只能选择上级已分配的权限；缩减上级权限会同步移除所有下级的相应权限。
            </p>
            <ElScrollbar max-height="420px" class="w-full pr-2">
              <ElTree
                :key="String(expanded)"
                ref="treeRef"
                :data="treeRows"
                node-key="key"
                show-checkbox
                check-strictly
                :default-expand-all="expanded"
                :default-checked-keys="checkedKeys"
                :expand-on-click-node="false"
                :props="{
                  label: 'title',
                  children: 'children',
                  disabled: 'disabled',
                  class: treeNodeClass
                }"
                class="[&_.el-tree-node__content]:!h-auto [&_.el-tree-node__content]:min-h-10 [&_.el-tree-node__content]:py-1"
                @check="checkAccess"
              >
                <template #default="{ data }">
                  <span>
                    {{ data.title }}
                    <span v-if="!data.effective_status" class="text-xs text-g-500">
                      （已停用）
                    </span>
                  </span>
                </template>
              </ElTree>
              <ElEmpty
                v-if="ready && !treeRows.length"
                description="暂无可分配的菜单权限"
                :image-size="64"
              />
            </ElScrollbar>
          </div>
        </ElFormItem>
        <ElFormItem label="状态">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="false" border>禁用</ElRadio>
            <ElRadio :value="true" border>启用</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem label="备注">
          <ElInput
            v-model="form.remark"
            type="textarea"
            :rows="2"
            maxlength="2000"
            show-word-limit
            placeholder="请输入备注"
          />
        </ElFormItem>
      </ElForm>
      <ElAlert
        v-if="visible && !loading && !ready"
        title="未能加载角色组资料，请关闭后重试。"
        type="error"
        :closable="false"
        class="mt-3"
      />
    </div>
    <template #footer>
      <ElButton :disabled="saving" @click="visible = false">{{
        readOnly ? '关闭' : '取消'
      }}</ElButton>
      <ElButton
        v-if="!readOnly"
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
  import {
    ElMessage,
    type FormInstance,
    type FormRules,
    type TreeInstance,
    type TreeNodeData
  } from 'element-plus'
  import {
    fetchCreateRole,
    fetchGetRole,
    fetchGetRoleList,
    fetchGetRoleOptions,
    fetchGetRolePermissionTree,
    fetchUpdateRole
  } from '@/api/system-manage'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'

  type RoleRecord = Api.SystemManage.RoleRecord
  type PermissionNode = Api.SystemManage.MenuPermissionNode
  interface TreeRow extends Omit<PermissionNode, 'children'> {
    disabled: boolean
    children: TreeRow[]
  }

  const props = defineProps<{ roleData?: RoleRecord; parentId?: number }>()
  const visible = defineModel<boolean>({ required: true })
  const emit = defineEmits<{ success: [] }>()
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const formRef = ref<FormInstance>()
  const treeRef = ref<TreeInstance>()
  const loading = ref(false)
  const saving = ref(false)
  const ready = ref(false)
  const expanded = ref(true)
  const roles = ref<RoleRecord[]>([])
  const permissionTree = ref<PermissionNode[]>([])
  const currentRole = ref<RoleRecord>()
  const isSuper = ref(false)
  const selectedIds = ref<number[]>([])
  const form = reactive({
    name: '',
    display_name: '',
    parent_id: 0 as number | null,
    status: true,
    remark: ''
  })
  const readOnly = computed(() =>
    props.roleData
      ? !hasAuth('edit') || !currentRole.value?.can_manage || props.roleData.name === 'super-admin'
      : !hasAuth('add')
  )
  let loadId = 0

  const flattenRoles = (nodes: RoleRecord[]): RoleRecord[] =>
    nodes.flatMap((role) => [role, ...flattenRoles(role.children || [])])
  const flatRoles = computed(() => flattenRoles(roles.value))
  const flattenPermissions = (nodes: PermissionNode[]): PermissionNode[] =>
    nodes.flatMap((node) => [node, ...flattenPermissions(node.children)])
  const flatPermissions = computed(() => flattenPermissions(permissionTree.value))
  const permissionsById = computed(
    () =>
      new Map(
        flatPermissions.value
          .filter((node) => node.permission_id !== null)
          .map((node) => [node.permission_id!, node])
      )
  )
  const parentPermissionIds = computed(() => {
    if (!form.parent_id) return null
    return new Set(flatRoles.value.find((role) => role.id === form.parent_id)?.permission_ids || [])
  })

  const parentOptions = computed(() => {
    const descendants = new Set<number>()
    const collect = (role: RoleRecord) => {
      descendants.add(role.id)
      role.children?.forEach(collect)
    }
    const editing = flatRoles.value.find((role) => role.id === props.roleData?.id)
    if (editing) collect(editing)
    return flatRoles.value.filter(
      (role) =>
        !descendants.has(role.id) &&
        (isSuper.value ||
          role.can_manage ||
          (role.effective_status && userStore.info.roles?.includes(role.name)) ||
          role.id === currentRole.value?.parent_id)
    )
  })

  function canSelect(node: PermissionNode): boolean {
    return (
      node.permission_id !== null &&
      node.assignable &&
      (!parentPermissionIds.value || parentPermissionIds.value.has(node.permission_id)) &&
      node.depends_on.every((id) => {
        const dependency = permissionsById.value.get(id)
        return (
          !!dependency?.assignable &&
          (!parentPermissionIds.value || parentPermissionIds.value.has(id))
        )
      })
    )
  }

  const treeRows = computed(() => {
    const convert = (nodes: PermissionNode[]): TreeRow[] =>
      nodes.map((node) => ({
        ...node,
        disabled:
          readOnly.value || loading.value || saving.value || !ready.value || !canSelect(node),
        children: convert(node.children)
      }))
    return convert(permissionTree.value)
  })

  function treeNodeClass(data: TreeNodeData): string {
    if (data.type === 'button') return 'permission-operation-node'
    if (data.type === 'menu') return 'permission-menu-node'
    return ''
  }

  const checkedKeys = computed(() => selectedIds.value.map((id) => `permission:${id}`))
  const rules: FormRules = {
    name: [{ required: true, whitespace: true, message: '请输入角色编码', trigger: 'blur' }],
    display_name: [
      { required: true, whitespace: true, message: '请输入角色组名称', trigger: 'blur' }
    ],
    parent_id: [
      {
        validator: (_rule, value, callback) => {
          if (!isSuper.value && !value) callback(new Error('请选择上级角色组'))
          else callback()
        },
        trigger: 'change'
      }
    ]
  }

  function setSelection(ids: number[]) {
    const selected = new Set(ids)
    ids.forEach((id) => {
      const node = permissionsById.value.get(id)
      if (node && canSelect(node)) node.depends_on.forEach((dependency) => selected.add(dependency))
    })
    selectedIds.value = [...selected]
    treeRef.value?.setCheckedKeys(checkedKeys.value)
  }

  function togglePermission(node: PermissionNode, checked: boolean) {
    if (
      readOnly.value ||
      saving.value ||
      !ready.value ||
      node.permission_id === null ||
      !canSelect(node)
    )
      return
    const selected = new Set(selectedIds.value)
    if (checked) {
      selected.add(node.permission_id)
      node.depends_on.forEach((id) => selected.add(id))
    } else {
      selected.delete(node.permission_id)
      flatPermissions.value.forEach((candidate) => {
        if (candidate.permission_id !== null && candidate.depends_on.includes(node.permission_id!))
          selected.delete(candidate.permission_id)
      })
    }
    setSelection([...selected])
  }

  function checkAccess(node: PermissionNode) {
    const checked = treeRef.value?.getCheckedKeys().includes(node.key) || false
    togglePermission(node, checked)
  }

  function changeParent() {
    setSelection(
      selectedIds.value.filter((id) => {
        const node = permissionsById.value.get(id)
        return node && canSelect(node)
      })
    )
  }

  function selectAll() {
    setSelection(flatPermissions.value.filter(canSelect).map((node) => node.permission_id!))
  }

  async function toggleExpand() {
    expanded.value = !expanded.value
    await nextTick()
    treeRef.value?.setCheckedKeys(checkedKeys.value)
  }

  watch(visible, async (open) => {
    const id = ++loadId
    ready.value = false
    if (!open) return
    loading.value = true
    currentRole.value = undefined
    permissionTree.value = []
    roles.value = []
    selectedIds.value = []
    isSuper.value = false
    Object.assign(form, { name: '', display_name: '', parent_id: 0, status: true, remark: '' })
    try {
      const [tree, groups, options, role] = await Promise.all([
        fetchGetRolePermissionTree(),
        fetchGetRoleList(),
        fetchGetRoleOptions(),
        props.roleData ? fetchGetRole(props.roleData.id) : Promise.resolve(undefined)
      ])
      if (id !== loadId || !visible.value) return
      permissionTree.value = tree
      roles.value = groups
      isSuper.value = options.some((option) => option.name === 'super-admin' && option.can_assign)
      currentRole.value = role
      if (role) {
        Object.assign(form, {
          name: role.name,
          display_name: role.display_name,
          parent_id: role.parent_id || 0,
          status: role.status,
          remark: role.remark || ''
        })
        setSelection(role.permission_ids)
      } else {
        form.parent_id =
          props.parentId ??
          (isSuper.value
            ? 0
            : (parentOptions.value.find((parent) => parent.effective_status)?.id ?? null))
      }
      ready.value = true
      await nextTick()
      treeRef.value?.setCheckedKeys(checkedKeys.value)
      formRef.value?.clearValidate()
    } catch {
      // HTTP 统一显示请求错误，并拒绝旧会话响应。
    } finally {
      if (id === loadId) loading.value = false
    }
  })

  async function save() {
    if (readOnly.value || !ready.value || saving.value || !formRef.value) return
    if (!(await formRef.value.validate().catch(() => false))) return
    saving.value = true
    try {
      const data = {
        display_name: form.display_name.trim(),
        parent_id: form.parent_id || null,
        status: form.status,
        remark: form.remark.trim() || null,
        permission_ids: [...selectedIds.value]
      }
      if (props.roleData) await fetchUpdateRole(props.roleData.id, data)
      else await fetchCreateRole({ ...data, name: form.name.trim() })
      ElMessage.success('角色组及权限已保存')
      visible.value = false
      emit('success')
    } catch {
      // 保存失败时保留输入，错误由 HTTP 统一提示。
    } finally {
      saving.value = false
    }
  }
</script>

<style>
  .role-dialog {
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 40px);
  }

  .role-dialog .el-dialog__header,
  .role-dialog .el-dialog__footer {
    flex-shrink: 0;
  }

  .role-dialog .el-dialog__body {
    min-height: 0;
    overflow-y: auto;
  }

  .role-dialog .permission-menu-node > .el-tree-node__children {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 16px;
    padding-left: 36px;
  }

  .role-dialog .permission-operation-node {
    flex: none;
  }

  .role-dialog .permission-operation-node > .el-tree-node__content {
    min-height: 28px !important;
    padding: 0 !important;
  }
</style>
