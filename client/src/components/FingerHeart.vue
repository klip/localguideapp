<script setup lang="ts">
/**
 * The brand mark: a finger heart (🫰) drawn by us, turned 10° clockwise,
 * with a small bee flying off the thumb (docs/design/sketches/09-…png).
 * Palm side: the thumb is the big finger pointing up, the tip of the index
 * peeks out behind it, the other fingers curl into the palm.
 *
 * Decorative — always `aria-hidden`; whoever places it says what it means.
 * Colours come from `--fh-ink`, `--fh-skin` and `--fh-wing`, so a dark
 * surface can recolour it. `weight` is the outline width in viewBox units:
 * thicker reads better small.
 */
withDefaults(defineProps<{ bee?: boolean; weight?: number }>(), { bee: true, weight: 4.5 })
</script>

<template>
  <svg
    class="finger-heart"
    viewBox="-5 -5 110 110"
    aria-hidden="true"
    focusable="false"
    :style="{ '--fh-w': weight }"
  >
    <g transform="rotate(10 55 60)">
      <!-- The index fingertip, behind the hand. -->
      <path
        class="fh-skin"
        d="M44 17 C35 11.5 25 8.5 18 11.5 C11.5 14.5 11.8 22.5 17.5 28 C23.5 34 30 39 37 44 Z"
      />
      <path class="fh-line fh-line--thin" d="M31 14.5 Q27.5 22 28 31" />
      <!-- Thumb, palm and curled fingers in one outline. -->
      <path
        class="fh-skin"
        d="M46.5 8 C50.5 2 59.5 2 61.5 8.5 C63 13 61.5 16.5 59.5 20.5 L53.5 33.5
           C52.3 36.3 53.6 38.6 56.5 39.6 L80 48.2 C87.5 51 92 56.5 92 63.5 L91.5 81
           C91 91.5 83.5 98.5 73 98.5 L54 98.2 C42 97.8 31.5 93.5 26.5 84.5
           C21 74 20.5 60 23.8 46.5 C27 33.5 35.5 20.5 46.5 8 Z"
      />
      <path
        class="fh-line"
        d="M66 43.5 C66 50.5 65 56.5 64 62.5 M79.5 48.5 C79.5 55 78.7 60.5 77.8 66"
      />
    </g>
    <g v-if="bee">
      <path class="fh-trail" d="M71 11 C74 6 78 5 81 6" />
      <g transform="translate(89 8) rotate(-12) scale(0.62)">
        <ellipse class="fh-wing" cx="-3" cy="-9" rx="6.5" ry="8.5" transform="rotate(-25 -3 -9)" />
        <ellipse class="fh-wing" cx="4" cy="-9.5" rx="5.5" ry="7.5" transform="rotate(20 4 -9.5)" />
        <ellipse class="fh-body" cx="0" cy="0" rx="13" ry="8.5" />
        <path class="fh-stripe" d="M-4 -8.2 Q-6 0 -4 8.2 M2.5 -8.4 Q0.5 0 2.5 8.4" />
        <path class="fh-stripe fh-stripe--thin" d="M-13 0 L-17 0" />
        <circle class="fh-eye" cx="8.5" cy="-1.5" r="1.6" />
        <path class="fh-stripe fh-stripe--antenna" d="M10 -7 Q13 -12 16 -12" />
      </g>
    </g>
  </svg>
</template>

<style scoped lang="scss">
.finger-heart {
  --_ink: var(--fh-ink, var(--rg-ink));
  --_skin: var(--fh-skin, var(--rg-honey));
  display: block;
  overflow: visible;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.fh-skin {
  fill: var(--_skin);
  stroke: var(--_ink);
  stroke-width: var(--fh-w);
}

.fh-line {
  fill: none;
  stroke: var(--_ink);
  stroke-width: calc(var(--fh-w) * 0.75);
}

.fh-line--thin {
  stroke-width: calc(var(--fh-w) * 0.6);
}

.fh-trail {
  fill: none;
  stroke: var(--_ink);
  stroke-width: calc(var(--fh-w) * 0.6);
  stroke-dasharray: 0.1 calc(var(--fh-w) * 1.4);
}

.fh-wing {
  fill: var(--fh-wing, #fff);
  stroke: var(--_ink);
  stroke-width: 2.2;
}

.fh-body {
  fill: var(--rg-honey);
  stroke: var(--_ink);
  stroke-width: 2.4;
}

.fh-stripe {
  fill: none;
  stroke: var(--_ink);
  stroke-width: 3;
}

.fh-stripe--thin {
  stroke-width: 2.4;
}

.fh-stripe--antenna {
  stroke-width: 1.8;
}

.fh-eye {
  fill: var(--_ink);
}
</style>
