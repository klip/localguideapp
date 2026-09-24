<script setup lang="ts">
import { computed } from 'vue'
import StarRating from './StarRating.vue'
import type { Review } from '@/plugins/api'
import { initialOf, timeAgo } from '@/utils/reviews'

/**
 * One review: avatar (photo, or the author's initial), name, stars, relative
 * date and the comment. The comment is user-supplied, so it's rendered as
 * text interpolation only — never `v-html`.
 */
const props = defineProps<{ review: Review }>()

const when = computed(() => timeAgo(props.review.created_at))
const edited = computed(() => props.review.updated_at !== props.review.created_at)
</script>

<template>
  <article class="review">
    <div class="review-head">
      <span
        v-if="review.author_image"
        class="avatar"
        :style="{ backgroundImage: `url('${review.author_image}')` }"
        aria-hidden="true"
      />
      <span v-else class="avatar avatar--initial" aria-hidden="true">{{
        initialOf(review.author_name)
      }}</span>
      <div>
        <strong class="author">{{ review.author_name }}</strong>
        <div class="meta">
          <StarRating :value="review.rating" />
          <time class="tiny muted" :datetime="review.created_at">{{ when }}</time>
          <span v-if="edited" class="tiny muted">· edited</span>
        </div>
      </div>
    </div>
    <p v-if="review.comment" class="comment">{{ review.comment }}</p>
  </article>
</template>

<style scoped lang="scss">
.review {
  padding: 0.85rem 0;
  border-top: 1px solid var(--rg-line);
}

.review-head {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.avatar {
  flex: none;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  background-size: cover;
  background-position: center;
}

.avatar--initial {
  display: grid;
  place-items: center;
  background: var(--rg-orange-100);
  color: var(--rg-orange-ink);
  font-weight: 700;
}

.author {
  display: block;
  line-height: 1.2;
}

.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
}

.comment {
  margin: 0.5rem 0 0;
  white-space: pre-line;
  overflow-wrap: anywhere;
}
</style>
