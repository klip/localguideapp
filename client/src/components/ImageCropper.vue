<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useMessagesStore } from '@/stores/messages'

/**
 * A minimal square-crop tool: pick a file, pan by dragging, zoom with a
 * slider — what's visible in the round preview is exactly what
 * `getCroppedDataUrl()` returns. No "confirm crop" step; the parent (e.g.
 * RegisterPage.vue) calls `getCroppedDataUrl()` at submit time to read the
 * current crop, or `null` if no photo was chosen.
 */

/** Size of the on-screen crop viewport, in CSS px. */
const VIEW_SIZE = 200
/** Size of the exported square image, in px. */
const OUTPUT_SIZE = 480
const MAX_SOURCE_BYTES = 8 * 1024 * 1024

const messages = useMessagesStore()

const fileInputRef = ref<HTMLInputElement | null>(null)
const imgRef = ref<HTMLImageElement | null>(null)

const imageSrc = ref<string | null>(null)
const naturalWidth = ref(0)
const naturalHeight = ref(0)
const zoom = ref(1)
const offsetX = ref(0)
const offsetY = ref(0)

let dragPointerId: number | null = null
let dragStart = { x: 0, y: 0, offsetX: 0, offsetY: 0 }

/** Scale that makes the image's shorter side exactly cover the viewport, before the zoom slider is applied. */
const baseScale = computed(() =>
  naturalWidth.value && naturalHeight.value
    ? VIEW_SIZE / Math.min(naturalWidth.value, naturalHeight.value)
    : 1,
)
const effectiveScale = computed(() => baseScale.value * zoom.value)

const displayWidth = computed(() => naturalWidth.value * effectiveScale.value)
const displayHeight = computed(() => naturalHeight.value * effectiveScale.value)

const imgStyle = computed(() => ({
  width: `${displayWidth.value}px`,
  height: `${displayHeight.value}px`,
  left: `${VIEW_SIZE / 2 + offsetX.value - displayWidth.value / 2}px`,
  top: `${VIEW_SIZE / 2 + offsetY.value - displayHeight.value / 2}px`,
}))

function clampOffsets() {
  const maxX = Math.max(0, (displayWidth.value - VIEW_SIZE) / 2)
  const maxY = Math.max(0, (displayHeight.value - VIEW_SIZE) / 2)
  offsetX.value = Math.min(maxX, Math.max(-maxX, offsetX.value))
  offsetY.value = Math.min(maxY, Math.max(-maxY, offsetY.value))
}

function revokeCurrent() {
  if (imageSrc.value) URL.revokeObjectURL(imageSrc.value)
}

function onFileChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  if (!file.type.startsWith('image/')) {
    messages.error('Please choose an image file.')
    return
  }
  if (file.size > MAX_SOURCE_BYTES) {
    messages.error('That image is too large — please choose one under 8MB.')
    return
  }

  revokeCurrent()
  zoom.value = 1
  offsetX.value = 0
  offsetY.value = 0
  imageSrc.value = URL.createObjectURL(file)
}

function onImageLoad() {
  if (!imgRef.value) return
  naturalWidth.value = imgRef.value.naturalWidth
  naturalHeight.value = imgRef.value.naturalHeight
}

function onZoomInput() {
  clampOffsets()
}

function startDrag(event: PointerEvent) {
  dragPointerId = event.pointerId
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  dragStart = { x: event.clientX, y: event.clientY, offsetX: offsetX.value, offsetY: offsetY.value }
}

function onDrag(event: PointerEvent) {
  if (dragPointerId !== event.pointerId) return
  offsetX.value = dragStart.offsetX + (event.clientX - dragStart.x)
  offsetY.value = dragStart.offsetY + (event.clientY - dragStart.y)
  clampOffsets()
}

function endDrag(event: PointerEvent) {
  if (dragPointerId !== event.pointerId) return
  dragPointerId = null
}

function changePhoto() {
  fileInputRef.value?.click()
}

function removePhoto() {
  revokeCurrent()
  imageSrc.value = null
  naturalWidth.value = 0
  naturalHeight.value = 0
  zoom.value = 1
  offsetX.value = 0
  offsetY.value = 0
  if (fileInputRef.value) fileInputRef.value.value = ''
}

/** Reads the current pan/zoom state and renders it to a square JPEG data URL, or `null` if no photo is loaded. */
function getCroppedDataUrl(): string | null {
  if (!imgRef.value || !naturalWidth.value) return null

  const canvas = document.createElement('canvas')
  canvas.width = OUTPUT_SIZE
  canvas.height = OUTPUT_SIZE
  const ctx = canvas.getContext('2d')
  if (!ctx) return null

  const outScale = OUTPUT_SIZE / VIEW_SIZE
  const drawWidth = displayWidth.value * outScale
  const drawHeight = displayHeight.value * outScale
  const left = OUTPUT_SIZE / 2 + offsetX.value * outScale - drawWidth / 2
  const top = OUTPUT_SIZE / 2 + offsetY.value * outScale - drawHeight / 2

  ctx.drawImage(imgRef.value, left, top, drawWidth, drawHeight)
  return canvas.toDataURL('image/jpeg', 0.85)
}

onBeforeUnmount(revokeCurrent)

defineExpose({ getCroppedDataUrl })
</script>

<template>
  <div class="cropper">
    <input
      ref="fileInputRef"
      type="file"
      accept="image/*"
      class="visually-hidden"
      @change="onFileChange"
    />

    <div v-if="!imageSrc" class="empty">
      <button type="button" class="soft-btn" @click="changePhoto">Add a photo</button>
      <p class="tiny muted">Optional — square crop, shown on your discovery card.</p>
    </div>

    <div v-else class="editor">
      <div
        class="viewport"
        @pointerdown="startDrag"
        @pointermove="onDrag"
        @pointerup="endDrag"
        @pointercancel="endDrag"
      >
        <img ref="imgRef" :src="imageSrc" alt="" class="viewport-img" :style="imgStyle" @load="onImageLoad" />
      </div>

      <label class="zoom tiny muted">
        Zoom
        <input
          type="range"
          min="1"
          max="3"
          step="0.05"
          v-model.number="zoom"
          @input="onZoomInput"
        />
      </label>

      <div class="actions">
        <button type="button" class="soft-btn" @click="changePhoto">Change photo</button>
        <button type="button" class="soft-btn" @click="removePhoto">Remove</button>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}

.empty {
  margin-bottom: 1rem;

  p {
    margin: 0.35rem 0 0;
  }
}

.editor {
  margin-bottom: 1rem;
}

.viewport {
  width: v-bind('`${VIEW_SIZE}px`');
  height: v-bind('`${VIEW_SIZE}px`');
  margin: 0 auto;
  position: relative;
  overflow: hidden;
  border-radius: 50%;
  border: 1px solid var(--rg-line);
  background: var(--rg-cream);
  cursor: grab;
  touch-action: none;

  &:active {
    cursor: grabbing;
  }
}

.viewport-img {
  position: absolute;
  max-width: none;
  user-select: none;
  -webkit-user-drag: none;
  pointer-events: none;
}

.zoom {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin: 0.6rem 0;

  input[type='range'] {
    width: 10rem;
  }
}

.actions {
  display: flex;
  justify-content: center;
  gap: 0.5rem;

  button {
    width: auto;
    margin-bottom: 0;
  }
}
</style>
