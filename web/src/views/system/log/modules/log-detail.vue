<template>
  <ElDialog
    v-model="visible"
    title="管理员日志详情"
    width="min(960px, calc(100% - 32px))"
    align-center
    destroy-on-close
  >
    <div v-loading="loading" class="min-h-48">
      <ElAlert
        v-if="failed"
        title="日志详情加载失败，记录可能已过期或不在当前可见范围内。"
        type="error"
        :closable="false"
      />
      <ElButton v-if="failed" class="mt-3" @click="loadDetail">重新加载</ElButton>
      <template v-if="detail">
        <ElAlert
          title="只读审计记录：邮箱和手机号已脱敏，密码仅显示修改标记。"
          type="info"
          :closable="false"
          class="mb-4"
        />
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-sm text-g-500">日志 ID / 时间（本地时区）</dt>
            <dd class="mt-1">#{{ detail.id }} · {{ formatLogTime(detail.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-sm text-g-500">操作 / 结果</dt>
            <dd class="mt-1 flex flex-wrap items-center gap-2">
              <span>{{ moduleLabels[detail.module] }} · {{ actionLabels[detail.action] }}</span>
              <ElTag :type="detail.success ? 'success' : 'danger'">
                {{ formatLogResult(detail) }} · {{ detail.status_code }}
              </ElTag>
            </dd>
          </div>
          <div>
            <dt class="text-sm text-g-500">操作人（已验证身份快照）</dt>
            <dd class="mt-1 break-all">
              {{ formatLogActor(detail) }}
              <span v-if="detail.actor_id !== null"> · ID {{ detail.actor_id }}</span>
              <p v-else class="mt-1 text-xs text-g-500">
                {{
                  detail.actor_username || detail.actor_nickname
                    ? '历史身份快照，账号已删除或无法关联。'
                    : '未记录已验证的管理员身份。'
                }}
              </p>
            </dd>
          </div>
          <div>
            <dt class="text-sm text-g-500">尝试登录的账号（未验证输入）</dt>
            <dd class="mt-1 break-all">{{ detail.attempted_username ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-sm text-g-500">操作对象</dt>
            <dd class="mt-1 break-all">{{ formatLogTarget(detail) }}</dd>
          </div>
          <div>
            <dt class="text-sm text-g-500">来源 IP / 耗时</dt>
            <dd class="mt-1 break-all"
              >{{ detail.ip_address || '—' }} · {{ detail.duration_ms }} ms</dd
            >
          </div>
          <div>
            <dt class="text-sm text-g-500">请求路由</dt>
            <dd class="mt-1 break-all">{{ detail.method }} {{ detail.path }}</dd>
          </div>
          <div>
            <dt class="text-sm text-g-500">路由名称 / 所需权限</dt>
            <dd class="mt-1 break-all"
              >{{ detail.route_name }} / {{ detail.permission_name || '—' }}</dd
            >
          </div>
          <div class="sm:col-span-2">
            <dt class="text-sm text-g-500">客户端</dt>
            <dd class="mt-1 whitespace-pre-wrap break-all">{{ detail.user_agent || '—' }}</dd>
          </div>
        </dl>
        <section class="mt-6">
          <h3 class="mb-3 text-base font-medium">字段变更</h3>
          <template v-if="detail.success">
            <LogDiff :changes="detail.changes" />
            <p class="mt-2 text-xs text-g-500">“—”表示空值；角色组和权限以 ID 展示。</p>
          </template>
          <p v-else class="text-sm text-g-500">本次操作失败，不记录字段变更。</p>
        </section>
        <section v-if="detail.success && detail.related_changes?.length" class="mt-6">
          <h3 class="mb-3 text-base font-medium">关联变更</h3>
          <div class="flex flex-col gap-4">
            <div
              v-for="(related, index) in detail.related_changes"
              :key="`${related.type}-${related.id}-${index}`"
            >
              <h4 class="mb-2 break-all text-sm font-medium">
                {{ moduleLabels[related.type] }} #{{ related.id }} · {{ related.label }}
              </h4>
              <LogDiff :changes="related.changes" />
            </div>
          </div>
        </section>
      </template>
    </div>
    <template #footer>
      <ElButton @click="visible = false">关闭</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { fetchGetAdminLog } from '@/api/admin-log'
  import LogDiff from './log-diff.vue'
  import {
    actionLabels,
    moduleLabels,
    formatLogActor,
    formatLogResult,
    formatLogTarget,
    formatLogTime
  } from './log-display'

  const visible = defineModel<boolean>('visible', { required: true })
  const props = defineProps<{ logId?: number }>()
  const detail = ref<Api.SystemManage.AdminLogRecord>()
  const loading = ref(false)
  const failed = ref(false)
  let requestId = 0

  async function loadDetail() {
    const id = ++requestId
    detail.value = undefined
    failed.value = false
    loading.value = false
    if (!visible.value || props.logId === undefined) return
    loading.value = true
    try {
      const data = await fetchGetAdminLog(props.logId)
      if (id === requestId) detail.value = data
    } catch {
      if (id === requestId) failed.value = true
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  watch(() => [visible.value, props.logId], loadDetail)
  onBeforeUnmount(() => requestId++)
</script>
