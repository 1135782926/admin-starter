<template>
  <ElInput
    v-model="icon"
    :disabled="disabled"
    :maxlength="255"
    placeholder="选择图标，或输入 Iconify 名称，如 ri:user-line"
    clearable
  >
    <template #prepend>
      <ArtSvgIcon :icon="previewIcon || 'ri:apps-line'" class="icon-picker-preview" />
    </template>
    <template #append>
      <ElButton :disabled="disabled" @click="openPicker">选择图标</ElButton>
    </template>
  </ElInput>

  <ElDialog
    v-model="visible"
    title="选择图标"
    width="min(760px, calc(100% - 24px))"
    align-center
    append-to-body
    destroy-on-close
    @opened="searchInput?.focus()"
  >
    <div class="icon-picker-toolbar">
      <ElInput
        ref="searchInput"
        v-model="search"
        :prefix-icon="Search"
        placeholder="搜索图标名称，如 user、menu、setting"
        aria-label="搜索图标名称"
        clearable
      />
      <div class="icon-picker-collection">
        <span>Remix Icon</span>
        <span v-if="icons.length">共 {{ icons.length }} 个</span>
      </div>
    </div>

    <div class="icon-picker-results">
      <div v-if="loading" class="icon-picker-state" role="status">正在加载图标…</div>
      <div v-else-if="failed" class="icon-picker-state" role="status">
        <span>图标目录加载失败</span>
        <ElButton link type="primary" @click="loadIcons">重试</ElButton>
      </div>
      <div v-else-if="!matches.length" class="icon-picker-state" role="status"
        >没有找到匹配的图标</div
      >
      <template v-else>
        <div ref="scrollArea" class="icon-picker-scroll" @scroll.passive="onScroll">
          <div class="icon-picker-grid">
            <button
              v-for="name in visibleIcons"
              :key="name"
              type="button"
              class="icon-picker-tile"
              :title="name"
              :aria-label="name"
              :aria-pressed="icon.trim() === name"
              @click="selectIcon(name)"
            >
              <ArtSvgIcon :icon="name" />
            </button>
          </div>
        </div>
        <div class="icon-picker-count">
          <span role="status">已显示 {{ visibleIcons.length }} / {{ matches.length }} 个图标</span>
          <ElButton v-if="hasMore" link type="primary" @click="showMore">加载更多</ElButton>
        </div>
      </template>
    </div>

    <template #footer>
      <ElButton type="primary" @click="visible = false">关闭</ElButton>
    </template>
  </ElDialog>
</template>

<script lang="ts">
  interface IconCollection {
    categories?: Record<string, string[]>
    uncategorized?: string[]
  }

  let remixIconNames: Promise<string[]> | undefined

  function getRemixIconNames(): Promise<string[]> {
    remixIconNames ??= fetch('https://api.iconify.design/collection?prefix=ri', {
      signal: AbortSignal.timeout(12000)
    })
      .then(async (response) => {
        if (!response.ok) throw new Error(`Iconify collection request failed: ${response.status}`)
        const collection: IconCollection = await response.json()
        const names = [
          ...Object.values(collection.categories ?? {}).flat(),
          ...(collection.uncategorized ?? [])
        ]
        if (!names.length) throw new Error('Iconify returned an empty collection')
        return [...new Set(names)].sort().map((name) => `ri:${name}`)
      })
      .catch((error: unknown) => {
        remixIconNames = undefined
        throw error
      })
    return remixIconNames
  }
</script>

<script setup lang="ts">
  import { Search } from '@element-plus/icons-vue'
  import { refDebounced } from '@vueuse/core'
  import type { InputInstance } from 'element-plus'
  import ArtSvgIcon from '@/components/core/base/art-svg-icon/index.vue'

  defineProps<{ disabled?: boolean }>()
  const icon = defineModel<string>({ required: true })
  const visible = ref(false)
  const loading = ref(false)
  const failed = ref(false)
  const search = ref('')
  const icons = shallowRef<string[]>([])
  const searchInput = useTemplateRef<InputInstance>('searchInput')
  const scrollArea = useTemplateRef<HTMLDivElement>('scrollArea')
  const pageSize = 56
  const limit = ref(pageSize)

  const previewIcon = refDebounced(
    computed(() => icon.value.trim()),
    250
  )
  const searchTerm = refDebounced(search, 150)
  const matches = computed(() => {
    const term = searchTerm.value.trim().toLowerCase().replace(/^ri:/, '')
    return term ? icons.value.filter((name) => name.slice(3).includes(term)) : icons.value
  })
  const visibleIcons = computed(() => matches.value.slice(0, limit.value))
  const hasMore = computed(() => limit.value < matches.value.length)

  watch(searchTerm, () => {
    limit.value = pageSize
    if (scrollArea.value) scrollArea.value.scrollTop = 0
  })

  function openPicker() {
    search.value = ''
    limit.value = pageSize
    visible.value = true
    void loadIcons()
  }

  async function loadIcons() {
    if (loading.value || icons.value.length) return
    loading.value = true
    failed.value = false
    try {
      icons.value = await getRemixIconNames()
    } catch {
      failed.value = true
    } finally {
      loading.value = false
    }
  }

  function showMore() {
    if (hasMore.value) limit.value += pageSize
  }

  function onScroll(event: Event) {
    const area = event.currentTarget as HTMLDivElement
    if (area.scrollHeight - area.scrollTop - area.clientHeight <= 80) showMore()
  }

  function selectIcon(name: string) {
    icon.value = name
    visible.value = false
  }
</script>

<style scoped>
  .icon-picker-preview {
    font-size: 18px;
  }

  .icon-picker-toolbar,
  .icon-picker-results {
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
  }

  .icon-picker-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px;
    margin-bottom: 16px;
  }

  .icon-picker-toolbar .el-input {
    flex: 1 1 240px;
    max-width: 420px;
  }

  .icon-picker-collection,
  .icon-picker-count {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  .icon-picker-collection span {
    padding: 3px 10px;
    border-radius: 12px;
    background: var(--el-fill-color-light);
  }

  .icon-picker-scroll {
    max-height: min(34vh, 284px);
    overflow: auto;
    overscroll-behavior: contain;
    padding: 16px;
    scrollbar-gutter: stable;
  }

  .icon-picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
    gap: 8px;
  }

  .icon-picker-tile {
    display: grid;
    min-height: 54px;
    place-items: center;
    border: 1px solid var(--el-border-color-light);
    border-radius: 6px;
    background: var(--el-fill-color-light);
    color: var(--el-text-color-regular);
    font-size: 20px;
    cursor: pointer;
  }

  .icon-picker-tile:is(:hover, :focus-visible, [aria-pressed='true']) {
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    color: var(--el-color-primary);
  }

  .icon-picker-tile:focus-visible {
    outline: 2px solid var(--el-color-primary);
    outline-offset: 1px;
  }

  .icon-picker-count {
    justify-content: space-between;
    padding: 10px 16px;
    border-top: 1px solid var(--el-border-color-light);
  }

  .icon-picker-state {
    display: flex;
    min-height: 144px;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--el-text-color-secondary);
  }
</style>
