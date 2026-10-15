<script setup lang="ts">
import { computed } from 'vue'
import { parseLegalMarkdown, placeholderSegments, sectionNumber } from '@/utils/legalMarkdown'

/**
 * A legal document's body (`api.legalDocument()`), rendered from its
 * Markdown subset as plain text (see `utils/legalMarkdown.ts`). Placeholders
 * the operator hasn't filled in yet show highlighted, so they're hard to miss
 * before launch. Headings start at `h3`: this sits under a dialog's `h2`;
 * each carries `data-section` ("9", "9.8") so a dialog can scroll to one.
 *
 * `translate="no"`: the text already exists in every UI language, and a
 * browser's machine translation of what someone is agreeing to can change
 * its meaning (Chrome was seen inventing a company name).
 */
const props = defineProps<{ body: string }>()

const blocks = computed(() => parseLegalMarkdown(props.body))
</script>

<template>
  <div class="legal" translate="no">
    <template v-for="(block, index) in blocks" :key="index">
      <h3 v-if="block.kind === 'heading'" :data-section="sectionNumber(block.text)">
        {{ block.text }}
      </h3>
      <h4 v-else-if="block.kind === 'subheading'" :data-section="sectionNumber(block.text)">
        {{ block.text }}
      </h4>
      <ul v-else-if="block.kind === 'list'">
        <li v-for="(item, itemIndex) in block.items" :key="itemIndex">
          <template v-for="(part, partIndex) in placeholderSegments(item)" :key="partIndex">
            <mark v-if="part.placeholder">{{ part.text }}</mark>
            <template v-else>{{ part.text }}</template>
          </template>
        </li>
      </ul>
      <p v-else>
        <template v-for="(part, partIndex) in placeholderSegments(block.text)" :key="partIndex">
          <mark v-if="part.placeholder">{{ part.text }}</mark>
          <template v-else>{{ part.text }}</template>
        </template>
      </p>
    </template>
  </div>
</template>

<style scoped lang="scss">
.legal {
  font-size: 0.9rem;
  line-height: 1.55;

  h3 {
    font-size: 1rem;
    margin: 1.25rem 0 0.5rem;
  }

  h4 {
    font-size: 0.92rem;
    margin: 1rem 0 0.4rem;
  }

  p,
  ul {
    margin-bottom: 0.75rem;
  }

  li {
    margin-bottom: 0.3rem;
  }

  // A placeholder takes its direction from its own text, so a Latin one keeps
  // its brackets the right way round inside right-to-left text.
  mark {
    unicode-bidi: plaintext;
  }
}
</style>
