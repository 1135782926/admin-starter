<!-- 菜单管理页面 -->
<template>
  <div class="menu-page art-full-height">
    <ArtSearchBar
      v-model="searchForm"
      :items="searchItems"
      :show-expand="false"
      @search="applySearch"
      @reset="resetSearch"
    />

    <ElAlert
      v-if="failed"
      title="菜单加载失败，请使用刷新重试。"
      type="error"
      :closable="false"
      class="mb-3"
    />

    <ElCard class="art-table-card min-h-64" :body-style="{ overflow: 'auto' }">
      <ArtTableHeader :loading="loading" v-model:columns="columnChecks" @refresh="loadMenus">
        <template #left>
          <ElSpace wrap>
            <ElButton v-if="canAdd" type="primary" @click="openCreate()">新增节点</ElButton>
            <ElButton @click="expanded = !expanded">
              {{ expanded ? '全部收起' : '全部展开' }}
            </ElButton>
          </ElSpace>
        </template>
      </ArtTableHeader>

      <div
        v-if="draggingNode"
        ref="rootDropZone"
        class="mb-2 flex items-center justify-center rounded-md border border-dashed border-theme bg-theme/5 p-2 text-sm text-theme"
        :class="{ 'menu-root-drop-active': dropTarget?.parent_id === null }"
        @dragover.prevent
      >
        将“{{ draggingNode.title }}”拖到此处可移动到根节点
      </div>
      <VueDraggable
        :key="String(expanded)"
        :model-value="filteredTree"
        target="tbody"
        handle=".menu-move-handle"
        :sort="false"
        :disabled="loading || dragSaving || !canEdit"
        @start="handleDragStart"
        @end="handleDragEnd"
      >
        <ArtTable
          :loading="loading || dragSaving"
          :columns="columns"
          :data="filteredTree"
          row-key="id"
          :row-class-name="dragRowClass"
          :tree-props="{ children: 'children' }"
          :default-expand-all="expanded"
          :empty-text="failed ? '菜单加载失败' : '暂无符合条件的菜单'"
        >
          <template #operation="{ row }">
            <div class="flex items-center" :data-menu-node-id="row.id">
              <ElTooltip
                v-if="canEdit && row.type !== 'button'"
                content="拖到节点上沿或下沿排序，拖到目录中央移入目录"
                placement="top"
              >
                <ArtButtonTable
                  class="menu-move-handle !cursor-move"
                  icon="ri:drag-move-2-fill"
                  icon-class="bg-info/12 text-info"
                />
              </ElTooltip>
              <ElTooltip v-if="canEdit" content="编辑" placement="top">
                <ArtButtonTable type="edit" @click="openEdit(row)" />
              </ElTooltip>
              <ElTooltip v-if="canDelete" content="删除" placement="top">
                <ArtButtonTable type="delete" @click="openDelete(row)" />
              </ElTooltip>
            </div>
          </template>
        </ArtTable>
      </VueDraggable>
    </ElCard>

    <MenuDialog
      v-model:visible="dialogVisible"
      :mode="dialogMode"
      :node="editingNode"
      :parent="parentNode"
      :allowed-types="allowedTypes"
      :nodes="tree"
      :saving="dialogSaving"
      @submit="handleDialogSubmit"
    />

    <ElDialog v-model="deleteVisible" title="删除影响预览" width="520px" align-center>
      <ElAlert
        v-if="deleteImpact && !deleteImpact.can_delete"
        title="该节点存在子节点，请先处理子节点后再删除。"
        type="error"
        :closable="false"
        class="mb-3"
      />
      <ElDescriptions :column="1" border>
        <ElDescriptionsItem label="节点名称">{{ deleteNode?.title }}</ElDescriptionsItem>
        <ElDescriptionsItem label="节点标识">{{ deleteNode?.name }}</ElDescriptionsItem>
        <ElDescriptionsItem label="直接子节点">{{
          deleteImpact?.children_count ?? '—'
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="受影响角色">{{
          deleteImpact?.roles_count ?? '—'
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="直接授权管理员">
          {{ deleteImpact?.direct_users_count ?? '—' }}
        </ElDescriptionsItem>
      </ElDescriptions>
      <p class="mt-3 text-sm text-[var(--el-text-color-secondary)]">
        删除会同时移除该节点绑定的权限及对应授权，操作不可恢复。
      </p>
      <template #footer>
        <ElButton :disabled="deleteSaving" @click="deleteVisible = false">取 消</ElButton>
        <ElButton
          type="danger"
          :loading="deleteSaving"
          :disabled="!deleteImpact?.can_delete"
          @click="confirmDelete"
          >确认删除</ElButton
        >
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { formatMenuTitle } from '@/utils/router'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useTableColumns } from '@/hooks/core/useTableColumns'
  import { useRouter } from 'vue-router'
  import { useEventListener } from '@vueuse/core'
  import type { SearchFormItem } from '@/components/core/forms/art-search-bar/index.vue'
  import { refreshAdminRouteContext } from '@/router/guards/beforeEach'
  import MenuDialog from './modules/menu-dialog.vue'
  import { VueDraggable } from 'vue-draggable-plus'
  import {
    allowedChildTypes,
    collectNodeAndDescendantIds,
    filterMenuTree,
    findMenuNode,
    menuTypeLabels,
    type MenuNode,
    type MenuOption,
    type MenuType
  } from './modules/menu-tree'
  import {
    fetchCreateAdminMenu,
    fetchDeleteAdminMenu,
    fetchGetAdminMenus,
    fetchGetMenuDeletionImpact,
    fetchUpdateAdminMenu
  } from '@/api/system-manage'
  import { ElTag } from 'element-plus'

  defineOptions({ name: 'Menus' })

  type MenuInput = Api.SystemManage.MenuInput
  type MenuUpdateInput = Api.SystemManage.MenuUpdateInput
  type MenuDeletionImpact = Api.SystemManage.MenuDeletionImpact
  type MenuSearchForm = Api.SystemManage.MenuSearchParams

  const router = useRouter()
  const { hasAuth } = useAuth()

  const canAdd = computed(() => hasAuth('add'))
  const canEdit = computed(() => hasAuth('edit'))
  const canDelete = computed(() => hasAuth('delete'))

  const loading = ref(false)
  const failed = ref(false)
  const expanded = ref(false)
  const tree = ref<MenuNode[]>([])

  const searchForm = ref<MenuSearchForm>({})
  const appliedSearch = ref<MenuSearchForm>({})

  const searchItems: SearchFormItem[] = [
    {
      label: '关键词',
      key: 'keyword',
      type: 'input',
      props: { clearable: true, placeholder: '名称、节点标识、权限标识或路由' }
    },
    {
      label: '节点类型',
      key: 'type',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部类型',
        options: Object.entries(menuTypeLabels).map(([value, label]) => ({ value, label }))
      }
    },
    {
      label: '状态',
      key: 'status',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部状态',
        options: [
          { label: '启用', value: true },
          { label: '停用', value: false }
        ]
      }
    }
  ]

  const typeTags: Record<MenuType, 'info' | 'primary' | 'warning'> = {
    directory: 'info',
    menu: 'primary',
    button: 'warning'
  }

  function formatTime(value: string | null): string {
    if (!value) return '—'
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('zh-CN', { hour12: false })
  }

  function errorMessage(error: unknown): string {
    return error instanceof Error && error.message ? error.message : '操作失败，请稍后重试'
  }

  const { columnChecks, columns } = useTableColumns<MenuOption>(() => [
    {
      prop: 'title',
      label: '名称',
      minWidth: 180,
      formatter: (row) => formatMenuTitle(row.title)
    },
    {
      prop: 'type',
      label: '类型',
      width: 100,
      formatter: (row) => h(ElTag, { type: typeTags[row.type] }, () => menuTypeLabels[row.type])
    },
    { prop: 'name', label: '节点标识', minWidth: 180, showOverflowTooltip: true },
    {
      prop: 'permission_name',
      label: '权限标识',
      minWidth: 180,
      formatter: (row) => row.permission_name || '—'
    },
    {
      prop: 'path',
      label: '路由 / 链接',
      minWidth: 180,
      showOverflowTooltip: true,
      formatter: (row) => (row.type === 'button' ? '—' : row.url || row.path || '—')
    },
    { prop: 'sort', label: '排序', width: 80 },
    {
      prop: 'status',
      label: '状态',
      width: 90,
      formatter: (row) =>
        h(ElTag, { type: row.status ? 'success' : 'info' }, () => (row.status ? '启用' : '停用'))
    },
    {
      prop: 'updated_at',
      label: '更新时间',
      minWidth: 180,
      formatter: (row) => formatTime(row.updated_at)
    },
    { prop: 'operation', label: '操作', width: 150, fixed: 'right', useSlot: true }
  ])

  const filteredTree = computed(() => {
    const keyword = appliedSearch.value.keyword?.toLowerCase().trim() || ''
    const type = appliedSearch.value.type
    const status = appliedSearch.value.status

    return filterMenuTree(tree.value, (node) => {
      const haystack = [node.title, node.name, node.permission_name, node.path, node.url]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      const matchesKeyword = !keyword || haystack.includes(keyword)
      const matchesType = !type || node.type === type
      const matchesStatus = status === undefined || node.status === status

      return matchesKeyword && matchesType && matchesStatus
    })
  })

  async function loadMenus(): Promise<void> {
    loading.value = true
    failed.value = false
    try {
      tree.value = await fetchGetAdminMenus()
    } catch (error) {
      tree.value = []
      failed.value = true
      ElMessage.error(errorMessage(error))
    } finally {
      loading.value = false
    }
  }

  function applySearch(): void {
    appliedSearch.value = {
      keyword: searchForm.value.keyword?.trim() || undefined,
      type: searchForm.value.type || undefined,
      status: typeof searchForm.value.status === 'boolean' ? searchForm.value.status : undefined
    }
  }

  function resetSearch(): void {
    searchForm.value = {}
    appliedSearch.value = {}
  }

  async function afterMutation(): Promise<void> {
    await loadMenus()
    try {
      await refreshAdminRouteContext(router)
    } catch (error) {
      ElMessage.warning(`权限刷新失败：${errorMessage(error)}`)
    }
  }

  const dialogVisible = ref(false)
  const dialogMode = ref<'create' | 'edit'>('create')
  const editingNode = ref<MenuNode | null>(null)
  const parentNode = ref<MenuNode | null>(null)
  const allowedTypes = ref<MenuType[]>([])
  const dialogSaving = ref(false)

  function openCreate(parent: MenuNode | null = null): void {
    if (!canAdd.value) return
    const types = allowedChildTypes(parent)
    if (types.length === 0) {
      ElMessage.warning('该节点下不能新增子节点')
      return
    }
    dialogMode.value = 'create'
    editingNode.value = null
    parentNode.value = parent
    allowedTypes.value = types
    dialogVisible.value = true
  }

  function openEdit(node: MenuNode): void {
    if (!canEdit.value) return
    dialogMode.value = 'edit'
    editingNode.value = node
    parentNode.value = findMenuNode(tree.value, node.parent_id) ?? null
    allowedTypes.value = [node.type]
    dialogVisible.value = true
  }

  async function handleDialogSubmit(payload: MenuInput | MenuUpdateInput): Promise<void> {
    if (dialogSaving.value) return
    dialogSaving.value = true
    try {
      if (dialogMode.value === 'edit' && editingNode.value) {
        await fetchUpdateAdminMenu(editingNode.value.id, payload as MenuUpdateInput)
      } else {
        await fetchCreateAdminMenu(payload as MenuInput)
      }
      ElMessage.success(dialogMode.value === 'edit' ? '保存成功' : '创建成功')
      dialogVisible.value = false
      await afterMutation()
    } catch (error) {
      ElMessage.error(errorMessage(error))
    } finally {
      dialogSaving.value = false
    }
  }

  const draggingNode = ref<MenuNode | null>(null)
  const rootDropZone = useTemplateRef<HTMLDivElement>('rootDropZone')
  const dragSaving = ref(false)
  const dropTarget = shallowRef<MenuUpdateInput>()
  let dragTableBody: HTMLElement | undefined

  useEventListener(document, ['dragover', 'mousemove', 'touchmove'], updateDropTarget, {
    capture: true,
    passive: true
  })

  function dragRowClass({ row }: { row: MenuNode }): string {
    if (draggingNode.value?.id === row.id) return 'menu-drag-source'
    const target = dropTarget.value
    if (target?.parent_id === row.id) return 'menu-drop-inside'
    if (target?.placement?.target_id === row.id) return `menu-drop-${target.placement.position}`
    return ''
  }

  function updateDropTarget(event: MouseEvent | TouchEvent): void {
    const node = draggingNode.value
    if (!node || !dragTableBody) return
    const next = resolveDrop(node, dragTableBody, event)
    const current = dropTarget.value
    if (
      current?.parent_id !== next?.parent_id ||
      current?.placement?.target_id !== next?.placement?.target_id ||
      current?.placement?.position !== next?.placement?.position
    ) {
      dropTarget.value = next
    }
  }

  function rowNodeId(element: HTMLElement): number | null {
    const id = element.querySelector<HTMLElement>('[data-menu-node-id]')?.dataset.menuNodeId
    return id ? Number(id) : null
  }

  function handleDragStart({ item, from }: { item: HTMLElement; from: HTMLElement }): void {
    const id = rowNodeId(item)
    draggingNode.value = id === null ? null : (findMenuNode(tree.value, id) ?? null)
    dragTableBody = from
  }

  function resolveDrop(
    node: MenuNode,
    tableBody: HTMLElement,
    event?: MouseEvent | TouchEvent
  ): MenuUpdateInput | undefined {
    if (!event || event.type === 'dragend' || event.type.endsWith('cancel')) return
    const point = 'changedTouches' in event ? event.changedTouches[0] : event
    if (!point) return
    const hit = document.elementFromPoint(point.clientX, point.clientY)
    if (!hit) return
    if (rootDropZone.value?.contains(hit)) {
      return node.parent_id === null ? undefined : { parent_id: null }
    }

    const row = hit.closest('tr')
    if (!row || row.parentElement !== tableBody) return
    const target = findMenuNode(tree.value, rowNodeId(row))
    if (!target || target.type === 'button' || collectNodeAndDescendantIds(node).has(target.id))
      return

    const { top, height } = row.getBoundingClientRect()
    const offset = (point.clientY - top) / height
    if (target.type === 'directory' && offset >= 1 / 3 && offset <= 2 / 3) {
      return node.parent_id === target.id ? undefined : { parent_id: target.id }
    }

    return { placement: { target_id: target.id, position: offset < 0.5 ? 'before' : 'after' } }
  }

  async function handleDragEnd({
    from,
    originalEvent
  }: {
    from: HTMLElement
    originalEvent?: MouseEvent | TouchEvent
  }): Promise<void> {
    const node = draggingNode.value
    const payload = node && resolveDrop(node, from, originalEvent)
    draggingNode.value = null
    dropTarget.value = undefined
    dragTableBody = undefined
    if (!node || !payload) return

    dragSaving.value = true
    try {
      await fetchUpdateAdminMenu(node.id, payload)
      ElMessage.success(payload.placement ? '排序已保存' : '移动成功')
      await afterMutation()
    } catch (error) {
      ElMessage.error(errorMessage(error))
      await loadMenus()
    } finally {
      dragSaving.value = false
    }
  }

  const deleteVisible = ref(false)
  const deleteNode = ref<MenuNode | null>(null)
  const deleteImpact = ref<MenuDeletionImpact | null>(null)
  const deleteLoading = ref(false)
  const deleteSaving = ref(false)
  let deletePreviewRequestId = 0

  async function openDelete(node: MenuNode): Promise<void> {
    if (!canDelete.value) return
    const requestId = ++deletePreviewRequestId
    deleteNode.value = node
    deleteImpact.value = null
    deleteVisible.value = true
    deleteLoading.value = true
    try {
      const impact = await fetchGetMenuDeletionImpact(node.id)
      if (requestId !== deletePreviewRequestId) return
      deleteImpact.value = impact
    } catch (error) {
      if (requestId !== deletePreviewRequestId) return
      deleteVisible.value = false
      ElMessage.error(errorMessage(error))
    } finally {
      if (requestId === deletePreviewRequestId) deleteLoading.value = false
    }
  }

  async function confirmDelete(): Promise<void> {
    const node = deleteNode.value
    if (!node || !deleteImpact.value?.can_delete || deleteSaving.value) return
    deleteSaving.value = true
    try {
      await fetchDeleteAdminMenu(node.id)
      ElMessage.success('删除成功')
      deleteVisible.value = false
      await afterMutation()
    } catch (error) {
      ElMessage.error(errorMessage(error))
    } finally {
      deleteSaving.value = false
    }
  }

  onMounted(loadMenus)
</script>

<style scoped>
  .menu-page :deep(.menu-drag-source > td) {
    background-color: var(--el-fill-color-light);
    opacity: 0.55;
  }

  .menu-page :deep(.menu-drop-before > td) {
    box-shadow: inset 0 3px var(--el-color-primary);
  }

  .menu-page :deep(.menu-drop-after > td) {
    box-shadow: inset 0 -3px var(--el-color-primary);
  }

  .menu-page :deep(.menu-drop-inside > td),
  .menu-root-drop-active {
    background-color: var(--el-color-primary-light-8);
  }
</style>
