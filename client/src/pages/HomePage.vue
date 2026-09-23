<script setup lang="ts">
import FeatureCard from '@/components/FeatureCard.vue'
import PhonePreview from '@/components/PhonePreview.vue'
import PillBadge from '@/components/PillBadge.vue'
import PriceBox from '@/components/PriceBox.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { guides } from '@/data/guides'
import { useAuthStore } from '@/stores/auth'
import { PACK_SIZE } from '@/stores/shortlist'

const auth = useAuthStore()
const heroGuide = guides[0]

const steps = [
  {
    title: '1. Unlock',
    body: `Pay £5 once to select up to ${PACK_SIZE} guides. Need more options? Unlock another ${PACK_SIZE} for £5.`,
  },
  {
    title: '2. Filter & swipe',
    body: 'Filter by language, age, gender and activity type. Save only guides you genuinely want to hear from.',
  },
  {
    title: '3. Match & arrange',
    body: 'Guides browse compatible visitors too. Once interest is mutual, contact details become available.',
  },
]
</script>

<template>
  <div>
    <section class="hero section shell">
      <div class="hero-grid">
        <div>
          <PillBadge>⚓ Made for Gibraltar cruise days</PillBadge>
          <h1>Find your local. Skip the tourist shuffle.</h1>
          <p class="lead">
            Match with independent Gibraltar guides based on your language, interests, pace and
            budget. Choose up to five guides for a one-time £5 access fee.
          </p>

          <div class="cta-row">
            <RouterLink to="/register" role="button">Find my guide · £5</RouterLink>
            <RouterLink
              v-if="!auth.isAuthenticated"
              :to="{ path: '/register', query: { role: 'guide' } }"
              role="button"
              class="soft-btn"
            >
              I’m a guide
            </RouterLink>
          </div>

          <div class="cta-row">
            <PillBadge>{{ PACK_SIZE }} guide selections included</PillBadge>
            <PillBadge>+{{ PACK_SIZE }} more for £5</PillBadge>
            <PillBadge>Direct contact after match</PillBadge>
          </div>
        </div>

        <PhonePreview v-if="heroGuide" :guide="heroGuide" progress-label="2 / 5 selected" />
      </div>
    </section>

    <section id="how" class="section shell">
      <SectionTitle
        eyebrow="Simple by design"
        heading="Three taps between “we’ve docked” and “we’ve got a plan.”"
      />
      <div class="grid-3">
        <FeatureCard v-for="step in steps" :key="step.title" v-bind="step" />
      </div>
    </section>

    <section class="section shell">
      <PriceBox>
        <div class="grid-3 price-grid">
          <div>
            <PillBadge>Visitor access</PillBadge>
            <div class="price">£5</div>
            <div class="muted">one-time selection fee</div>
          </div>
          <div>
            <strong>Includes {{ PACK_SIZE }} guide selections</strong>
            <p class="muted no-margin">
              No subscription. No marketplace clutter. Just a focused shortlist.
            </p>
          </div>
          <div>
            <RouterLink to="/register" role="button" class="full">Start matching</RouterLink>
          </div>
        </div>
      </PriceBox>
    </section>
  </div>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.hero {
  padding: {top: 3.5rem; bottom: 2.2rem;};

  @include bp.md {
    padding-top: 5rem;
  }
}

.hero-grid {
  display: grid;
  gap: 2rem;
  align-items: center;

  @include bp.md {
    grid-template-columns: 1.15fr 0.85fr;
  }
}

h1 {
  font-size: clamp(2.55rem, 7vw, 5.4rem);
  line-height: 0.95;
  letter-spacing: -0.055em;
  max-width: 11ch;
  margin: 1rem 0 1.1rem;
}

.lead {
  font-size: 1.05rem;
  color: var(--rg-muted);
  max-width: 52ch;
}

.cta-row {
  display: flex;
  gap: 0.7rem;
  flex-wrap: wrap;
  margin: 1.2rem 0;

  a[role='button'] {
    margin-bottom: 0;
  }
}

.price-grid {
  align-items: center;
}

.no-margin {
  margin-bottom: 0;
}
</style>
