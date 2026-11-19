<template>
  <div>
    <div class="flex flex-wrap gap-2">
      <div
        v-for="(url, index) in previewUrls"
        :key="`${index}:${url}`"
        class="image-preview-card relative size-[148px] shrink-0 overflow-hidden rounded-md border"
      >
        <ElImage :src="url" fit="contain" alt="图片预览" class="size-full" />
        <span
          class="image-preview-check absolute top-0 right-0 grid size-7 justify-items-end p-0.5"
          aria-hidden="true"
        >
          <ElIcon :size="12"><Check /></ElIcon>
        </span>
      </div>
      <div
        class="image-upload-card grid size-[148px] shrink-0 grid-rows-[30px_1fr_30px] rounded-md border border-dashed transition-colors"
        :class="{ 'is-disabled': disabled }"
      >
        <button
          type="button"
          class="image-upload-action rounded-b-2xl border-b border-dashed text-xs"
          :disabled="disabled || !allowLibrary"
          :title="allowLibrary ? '从附件库选择图片' : '需要附件查看权限'"
          @click="libraryVisible = true"
        >
          选择
        </button>
        <button
          type="button"
          class="image-upload-action image-upload-plus grid min-h-0 place-items-center"
          aria-label="上传本地图片"
          :disabled="disabled"
          @click="fileInput?.click()"
        >
          <ElIcon :size="26"><Plus /></ElIcon>
        </button>
        <button
          type="button"
          class="image-upload-action rounded-t-2xl border-t border-dashed text-xs"
          :disabled="disabled"
          @click="readClipboard"
        >
          截图上传
        </button>
      </div>
    </div>
    <input
      ref="fileInput"
      type="file"
      hidden
      :accept="accept"
      :multiple="multiple"
      :disabled="disabled"
      @change="selectLocal"
    />
    <p class="m-0 mt-1 text-xs leading-5 text-g-500"
      >JPG、PNG、WebP、GIF，最大 {{ maxSizeMb }} MB</p
    >

    <ImageLibraryDialog
      v-if="allowLibrary"
      v-model:visible="libraryVisible"
      :max-bytes="maxBytes"
      @select="selectImages([$event])"
    />
    <ElDialog
      v-model="pasteVisible"
      title="截图上传"
      width="min(420px, calc(100% - 32px))"
      append-to-body
      align-center
      destroy-on-close
      @opened="pasteInput?.focus()"
      @paste.prevent="pasteImage"
    >
      <p class="m-0 mb-3 text-sm">复制图片后，将图片粘贴到下方（Ctrl+V 或 ⌘V）。</p>
      <ElInput
        ref="pasteInput"
        type="textarea"
        :rows="3"
        aria-label="粘贴图片"
        placeholder="在此粘贴图片"
      />
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { Check, Plus } from '@element-plus/icons-vue'
  import { ElMessage, type InputInstance } from 'element-plus'
  import ImageLibraryDialog from './image-library-dialog.vue'

  const props = withDefaults(
    defineProps<{
      src?: string | string[] | null
      multiple?: boolean
      disabled?: boolean
      allowLibrary?: boolean
      maxSizeMb?: number
    }>(),
    { maxSizeMb: 2 }
  )
  const file = defineModel<File>()
  const files = defineModel<File[]>('files')
  const fileInput = ref<HTMLInputElement>()
  const pasteInput = ref<InputInstance>()
  const libraryVisible = ref(false)
  const pasteVisible = ref(false)
  const localUrls = shallowRef<string[]>([])
  const objectUrls = new Map<File, string>()
  const maxBytes = computed(() => props.maxSizeMb * 1024 * 1024)
  const imageTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
  const accept = imageTypes.join(',')
  let clipboardRequest = 0

  const previewUrls = computed(() => {
    const saved = Array.isArray(props.src)
      ? props.src.filter(Boolean)
      : props.src
        ? [props.src]
        : []
    if (props.multiple) return [...saved, ...localUrls.value]
    return localUrls.value.length ? localUrls.value : saved.slice(0, 1)
  })

  watch(
    () => (props.multiple ? (files.value?.slice() ?? []) : file.value ? [file.value] : []),
    (images) => {
      const selected = new Set(images)
      for (const [image, url] of objectUrls) {
        if (!selected.has(image)) {
          URL.revokeObjectURL(url)
          objectUrls.delete(image)
        }
      }
      localUrls.value = images.map((image) => {
        const existingUrl = objectUrls.get(image)
        if (existingUrl) return existingUrl
        const url = URL.createObjectURL(image)
        objectUrls.set(image, url)
        return url
      })
    },
    { immediate: true }
  )

  onUnmounted(() => objectUrls.forEach((url) => URL.revokeObjectURL(url)))

  function isValidImage(image: File) {
    if (!imageTypes.includes(image.type)) {
      ElMessage.warning('请选择 JPG、PNG、WebP 或 GIF 图片')
      return false
    }
    if (image.size > maxBytes.value) {
      ElMessage.warning(`图片不能超过 ${props.maxSizeMb} MB`)
      return false
    }
    return true
  }

  function selectImages(images: File[]) {
    if (props.disabled) return false
    const accepted = (props.multiple ? images : images.slice(0, 1)).filter(isValidImage)
    if (!accepted.length) return false
    if (props.multiple) files.value = [...(files.value ?? []), ...accepted]
    else file.value = accepted[0]
    return true
  }

  function selectLocal(event: Event) {
    const input = event.currentTarget as HTMLInputElement
    const images = Array.from(input.files ?? [])
    input.value = ''
    selectImages(images)
  }

  async function readClipboard() {
    const requestId = ++clipboardRequest
    pasteVisible.value = true
    try {
      const items = await navigator.clipboard?.read?.()
      for (const item of items ?? []) {
        const type = item.types.find((mime) => imageTypes.includes(mime))
        if (!type) continue
        const blob = await item.getType(type)
        if (requestId !== clipboardRequest || !pasteVisible.value) return
        const extension = type === 'image/jpeg' ? 'jpg' : type.split('/')[1]
        if (selectImages([new File([blob], `paste-${Date.now()}.${extension}`, { type })])) {
          pasteVisible.value = false
        }
        return
      }
    } catch {
      // Keep the keyboard paste dialog open when Clipboard API access is denied.
    }
  }

  function pasteImage(event: ClipboardEvent) {
    const images = Array.from(event.clipboardData?.files ?? []).filter((item) =>
      item.type.startsWith('image/')
    )
    if (!images.length) {
      ElMessage.warning('剪贴板中没有图片，请先复制图片或截图')
      return
    }
    if (selectImages(images)) pasteVisible.value = false
  }
</script>

<style scoped>
  .image-preview-card {
    border-color: var(--el-border-color);
    background: var(--el-bg-color);
  }

  .image-preview-check {
    color: var(--el-color-white);
    background: var(--el-color-success);
    clip-path: polygon(0 0, 100% 0, 100% 100%);
  }

  .image-upload-card {
    background: var(--el-fill-color-lighter);
    border-color: var(--el-border-color-darker);
  }

  .image-upload-card:not(.is-disabled):is(:hover, :focus-within) {
    border-color: var(--el-color-primary);
  }

  .image-upload-plus {
    color: var(--el-text-color-placeholder);
  }

  .image-upload-action {
    border-color: var(--el-border-color);
    cursor: pointer;
  }

  .image-upload-action:enabled:hover {
    border-color: var(--el-color-primary);
  }

  .image-upload-action:focus-visible {
    outline: 2px solid var(--el-color-primary);
    outline-offset: -2px;
  }

  .image-upload-action:disabled {
    color: var(--el-text-color-disabled);
    cursor: not-allowed;
  }
</style>
