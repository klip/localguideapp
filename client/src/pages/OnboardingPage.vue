<script setup lang="ts">
import { reactive } from 'vue'
import { useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'

const router = useRouter()

const profile = reactive({
  name: '',
  contactMethod: 'WhatsApp',
  contactDetail: '',
})

function save() {
  router.push('/discover')
}
</script>

<template>
  <section class="section shell">
    <SectionTitle eyebrow="Screen 02" heading="Short visitor configuration" />

    <PanelCard
      class="narrow"
      title="Tell guides how to reach you"
      subtitle="Only matched guides will see your contact details."
    >
      <form @submit.prevent="save">
        <label>
          Name or nickname
          <input v-model="profile.name" placeholder="Igor" required />
        </label>

        <label>
          Preferred contact
          <select v-model="profile.contactMethod">
            <option>WhatsApp</option>
            <option>Phone</option>
            <option>Email</option>
          </select>
        </label>

        <label>
          Contact detail
          <input v-model="profile.contactDetail" placeholder="+350 … / email" required />
        </label>

        <label>
          Profile photo
          <input type="file" accept="image/*" />
        </label>

        <button type="submit" class="full">Save &amp; start exploring</button>
      </form>
    </PanelCard>
  </section>
</template>

<style scoped lang="scss">
label {
  margin-bottom: 1rem;
  display: block;
}
</style>
