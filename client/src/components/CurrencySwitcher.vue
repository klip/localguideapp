<script setup lang="ts">
import { t } from '@/i18n'
import {
  activeCurrency,
  autoCurrency,
  availableCurrencies,
  chosenCurrency,
  setCurrency,
} from '@/utils/money'

/**
 * The header's currency picker, beside the language. "Automatic" follows
 * where the viewer is (`detectCurrency()` — the device's time zone, then the
 * browser's region); any other choice is remembered on this browser. Prices
 * change at once — no reload — and charges stay in pounds (the payment
 * notices say so). Each option reads "EUR · €".
 */
function symbolOf(code: string) {
  try {
    const part = new Intl.NumberFormat('en', { style: 'currency', currency: code })
      .formatToParts(0)
      .find((p) => p.type === 'currency')
    return part && part.value !== code ? ` · ${part.value}` : ''
  } catch {
    return ''
  }
}

function onChange(event: Event) {
  setCurrency((event.target as HTMLSelectElement).value)
}
</script>

<template>
  <select
    class="currency-switcher"
    :value="chosenCurrency && availableCurrencies.includes(chosenCurrency) ? chosenCurrency : ''"
    :aria-label="t('common.currency_switcher.label')"
    :title="t('common.currency_switcher.label')"
    data-test="currency-switcher"
    @change="onChange"
  >
    <option value="">
      {{
        t('common.currency_switcher.auto', { code: chosenCurrency ? autoCurrency : activeCurrency })
      }}
    </option>
    <option v-for="code in availableCurrencies" :key="code" :value="code">
      {{ code }}{{ symbolOf(code) }}
    </option>
  </select>
</template>

<style scoped lang="scss">
.currency-switcher {
  width: auto;
  margin: 0;
  padding: 0.3rem 2rem 0.3rem 0.6rem;
  font-size: 0.85rem;
}
</style>
