<!-- 基础表格 -->
<template>
  <div class="user-page art-full-height">
    <ElCard class="art-table-card" style="margin-top: 0">
      <!-- 表格 -->
      <ArtTable
        rowKey="id"
        :show-table-header="false"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
      </ArtTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import { fetchGetAdminList } from '@/api/system-manage'

  defineOptions({ name: 'UserMixedUsageExample' })

  const { data, columns, loading, pagination, handleSizeChange, handleCurrentChange } = useTable({
    core: {
      apiFn: fetchGetAdminList,
      paginationKey: { current: 'page', size: 'per_page' },
      apiParams: { page: 1, per_page: 20 },
      columnsFactory: () => [
        {
          prop: 'id',
          label: 'ID'
        },
        {
          prop: 'nickname',
          label: '昵称'
        },
        {
          prop: 'username',
          label: '用户名',
          sortable: true
        },
        {
          prop: 'mobile',
          label: '手机号'
        },
        {
          prop: 'email',
          label: '邮箱'
        }
      ]
    }
  })
</script>
