<template>
  <div class="@container flex flex-col gap-4 py-4 sm:gap-5 sm:py-5">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-bold tracking-tight text-g-900 sm:text-[28px]">运营概览</h1>
      <div class="flex flex-wrap items-center gap-3">
        <span class="inline-flex items-center gap-1.5 text-xs text-g-700">
          <span class="size-1.5 rounded-full bg-violet-500" aria-hidden="true"></span>
          演示数据
        </span>
        <ElSegmented v-model="selectedDays" :options="ranges" aria-label="统计周期" />
      </div>
    </header>

    <section class="console-hero relative isolate overflow-hidden rounded-2xl text-white">
      <img
        :src="heroImage"
        alt=""
        width="1024"
        height="576"
        class="console-hero-art pointer-events-none absolute inset-y-0 right-0 h-full w-3/4 max-w-[510px] object-cover object-[right_46%] opacity-55 @lg:w-1/2 @lg:opacity-100"
      />
      <div class="relative flex min-h-42 flex-col items-start justify-center gap-3 p-5 sm:p-7">
        <p class="flex items-center gap-2 text-xs font-medium text-violet-200 sm:text-sm">
          <ArtSvgIcon icon="ri:calendar-2-line" class="text-base" aria-hidden="true" />
          {{ dateRange }}
        </p>
        <h2
          class="max-w-full truncate text-[26px] leading-tight font-semibold @lg:max-w-[60%] sm:text-[32px]"
        >
          欢迎回来，{{ displayName }}
        </h2>
        <button
          type="button"
          class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/20 bg-white/8 px-2.5 text-xs font-medium text-white transition-colors hover:bg-white/18 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          aria-controls="console-activity"
          @click="showActivity"
        >
          查看近期动态
          <ArtSvgIcon icon="ri:arrow-down-line" class="text-sm" aria-hidden="true" />
        </button>
      </div>
    </section>

    <section
      aria-label="关键指标"
      class="grid grid-cols-1 gap-3 @min-[320px]:grid-cols-2 @min-[880px]:grid-cols-4 sm:gap-4"
    >
      <ArtStatsCard
        v-for="metric in metrics"
        :key="metric.title"
        :count="metric.current"
        :icon="metric.icon"
        :icon-style="`!mr-0 !size-8 shrink-0 !text-base sm:!size-10 sm:!text-lg ${metric.iconStyle}`"
        box-style="!h-28 flex-row-reverse !items-start gap-1 !p-3 sm:gap-2 sm:!p-4"
      >
        <template #title>
          <p class="mb-1.5 text-xs font-medium text-g-700 sm:text-sm">{{ metric.title }}</p>
        </template>
        <template #description>
          <div class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs">
            <span
              class="inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 font-semibold tabular-nums"
              :class="
                metric.change >= 0
                  ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                  : 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
              "
            >
              <ArtSvgIcon
                :icon="metric.change >= 0 ? 'ri:arrow-right-up-line' : 'ri:arrow-right-down-line'"
                aria-hidden="true"
              />
              {{ changeFormatter.format(metric.change) }}
            </span>
            <span class="text-g-700">较上期</span>
          </div>
        </template>
      </ArtStatsCard>
    </section>

    <div class="grid gap-4 @min-[850px]:grid-cols-[minmax(0,1.8fr)_minmax(0,1fr)] sm:gap-5">
      <section
        class="art-card min-w-0 overflow-clip p-4 sm:p-5"
        aria-labelledby="console-trend-title"
      >
        <header class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h2 id="console-trend-title" class="text-base font-semibold text-g-900">访问趋势</h2>
            <p class="mt-1 text-xs text-g-700">每日访问次数</p>
          </div>
          <div class="flex gap-3 text-xs text-g-700">
            <span
              v-for="(series, index) in trendSeries"
              :key="series.name"
              class="inline-flex items-center gap-1.5"
            >
              <span
                class="h-0.5 w-3 rounded-full"
                :style="{ backgroundColor: trendColors[index] }"
                aria-hidden="true"
              ></span>
              {{ series.name }}
            </span>
          </div>
        </header>
        <ArtLineChart
          :key="selectedDays"
          class="mt-3"
          height="216px"
          :data="trendSeries"
          :x-axis-data="axisDates"
          :colors="trendColors"
          :show-axis-line="false"
          role="img"
          :aria-label="`本期访问 ${numberFormatter.format(totalVisits)} 次，上期访问 ${numberFormatter.format(previousVisits)} 次`"
        />
      </section>

      <section
        class="art-card min-w-0 overflow-clip p-4 sm:p-5"
        aria-labelledby="console-sources-title"
      >
        <h2 id="console-sources-title" class="text-base font-semibold text-g-900">访问来源</h2>
        <p class="mt-1 text-xs text-g-700">各渠道访问次数占比</p>
        <div class="relative mt-2">
          <ArtRingChart
            height="156px"
            :data="sources"
            :colors="sourceColors"
            :radius="ringRadius"
            :border-radius="3"
            aria-hidden="true"
          />
          <div
            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"
          >
            <span class="text-xl leading-tight font-semibold text-g-900 tabular-nums">{{
              numberFormatter.format(totalVisits)
            }}</span>
            <span class="mt-1 text-xs text-g-700">总访问次数</span>
          </div>
        </div>
        <ul class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-xs sm:gap-x-5">
          <li
            v-for="(source, index) in sources"
            :key="source.name"
            class="flex items-center gap-1.5"
          >
            <span
              class="size-2 shrink-0 rounded-full"
              :style="{ backgroundColor: sourceColors[index] }"
              aria-hidden="true"
            ></span>
            <span class="text-g-700">{{ source.name }}</span>
            <span class="ml-auto font-semibold text-g-900 tabular-nums">{{ source.share }}%</span>
          </li>
        </ul>
      </section>
    </div>

    <section
      id="console-activity"
      ref="activitySection"
      class="scroll-mt-28"
      aria-label="近期动态"
      tabindex="-1"
    >
      <ArtDataListCard title="近期动态" :list="activities" :max-count="activities.length" />
    </section>
  </div>
</template>

<script setup lang="ts">
  import heroImage from '@/assets/images/console-insight-hero.webp'
  import { useUserStore } from '@/store/modules/user'
  import type { LineDataItem } from '@/types/component/chart'

  defineOptions({ name: 'Console' })

  const userStore = useUserStore()
  const displayName = computed(() => userStore.info.userName || '管理员')
  const activitySection = ref<HTMLElement | null>(null)

  function showActivity() {
    activitySection.value?.focus({ preventScroll: true })
    activitySection.value?.scrollIntoView({ block: 'start' })
  }

  const selectedDays = ref<7 | 30>(7)
  const ranges = [
    { label: '近 7 天', value: 7 },
    { label: '近 30 天', value: 30 }
  ]
  const today = new Date()
  const dateFormatter = new Intl.DateTimeFormat('zh-CN', { month: 'long', day: 'numeric' })
  const numberFormatter = new Intl.NumberFormat('zh-CN')
  const changeFormatter = new Intl.NumberFormat('zh-CN', {
    style: 'percent',
    signDisplay: 'exceptZero',
    minimumFractionDigits: 1,
    maximumFractionDigits: 1
  })

  const dates = computed(() =>
    Array.from({ length: selectedDays.value }, (_, index) => {
      const date = new Date(today)
      date.setDate(today.getDate() - selectedDays.value + index)
      return date
    })
  )
  const dateRange = computed(() => {
    const first = dates.value[0]
    const last = dates.value[dates.value.length - 1]
    return `${dateFormatter.format(first)} – ${dateFormatter.format(last)}`
  })
  const axisDates = computed(() =>
    dates.value.map((date) => `${date.getMonth() + 1}/${date.getDate()}`)
  )

  const dailyVisits = [
    1480, 1640, 1530, 1890, 2020, 1810, 1540, 1720, 1890, 2010, 1930, 2020, 2220, 1780, 1950, 2130,
    2300, 2040, 2380, 1740, 1920, 2360, 2100, 2220, 2090, 2550, 2650, 2410, 2170, 2540, 2030, 2180,
    2270, 2020, 2430, 2640, 2340, 2140, 2370, 2230, 2790, 2630, 2360, 2460, 2710, 2590, 2820, 3060,
    2830, 2420, 2510, 2780, 2430, 2040, 2980, 2620, 2870, 3230, 2930, 3650
  ]
  const trendColors = ['#7a5be7', '#9aa9c2']
  const trendSeries = computed<LineDataItem[]>(() => {
    const days = selectedDays.value
    return [
      { name: '本期', data: dailyVisits.slice(-days), showAreaColor: true, lineWidth: 3 },
      { name: '上期', data: dailyVisits.slice(-2 * days, -days), lineWidth: 2 }
    ]
  })
  const totalVisits = computed(() =>
    trendSeries.value[0].data.reduce((sum, count) => sum + count, 0)
  )
  const previousVisits = computed(() =>
    trendSeries.value[1].data.reduce((sum, count) => sum + count, 0)
  )

  const metricSamples = [
    {
      title: '新增用户',
      icon: 'ri:user-add-line',
      iconStyle: 'bg-sky-100 !text-sky-700 dark:bg-sky-500/15 dark:!text-sky-300',
      counts: { 7: [1268, 1108], 30: [5186, 4739] }
    },
    {
      title: '内容发布',
      icon: 'ri:article-line',
      iconStyle: 'bg-amber-100 !text-amber-700 dark:bg-amber-500/15 dark:!text-amber-300',
      counts: { 7: [386, 350], 30: [1428, 1292] }
    },
    {
      title: '附件上传',
      icon: 'ri:attachment-2',
      iconStyle: 'bg-teal-100 !text-teal-700 dark:bg-teal-500/15 dark:!text-teal-300',
      counts: { 7: [846, 906], 30: [3642, 3311] }
    }
  ]
  const metrics = computed(() => [
    {
      title: '访问次数',
      icon: 'ri:bar-chart-line',
      iconStyle: 'bg-violet-100 !text-violet-700 dark:bg-violet-500/15 dark:!text-violet-300',
      current: totalVisits.value,
      change: totalVisits.value / previousVisits.value - 1
    },
    ...metricSamples.map(({ counts, ...metric }) => {
      const [current, previous] = counts[selectedDays.value]
      return { ...metric, current, change: current / previous - 1 }
    })
  ])

  const sourceChannels = [
    { name: '搜索引擎', share: { 7: 46, 30: 42 } },
    { name: '直接访问', share: { 7: 27, 30: 29 } },
    { name: '社交平台', share: { 7: 17, 30: 16 } },
    { name: '外部链接', share: { 7: 10, 30: 13 } }
  ]
  const sourceColors = ['#7a5be7', '#aaaaed', '#4ec4b4', '#f2b773']
  const ringRadius: [string, string] = ['72%', '93%']
  const sources = computed(() => {
    let remaining = totalVisits.value
    return sourceChannels.map(({ name, share }, index) => {
      const percent = share[selectedDays.value]
      const value =
        index === sourceChannels.length - 1
          ? remaining
          : Math.round((totalVisits.value * percent) / 100)
      remaining -= value
      return { name, value, share: percent }
    })
  })

  const activities = [
    {
      title: '新用户注册',
      status: '林小满加入了平台',
      time: '12 分钟前',
      icon: 'ri:user-add-line',
      class: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
    },
    {
      title: '附件上传',
      status: '许晨上传了 12 个附件',
      time: '38 分钟前',
      icon: 'ri:attachment-2',
      class: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300'
    },
    {
      title: '内容发布',
      status: '编辑部发布了《产品使用指南》',
      time: '1 小时前',
      icon: 'ri:article-line',
      class: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
    },
    {
      title: '资料更新',
      status: '陈悦更新了账号资料',
      time: '2 小时前',
      icon: 'ri:user-settings-line',
      class: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'
    }
  ]
</script>

<style scoped>
  .console-hero {
    background: linear-gradient(108deg, #201245, #26164d 67%, #1e123f);
  }

  .console-hero-art {
    mask-image: linear-gradient(90deg, transparent, #000 40%);
  }
</style>
