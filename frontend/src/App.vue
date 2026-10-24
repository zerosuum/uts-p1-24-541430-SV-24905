<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import api, { TOKEN_KEY } from './services/api'

type TaskStatus = 'inbox' | 'in_progress' | 'done'
type Tab = 'active' | 'done'

interface Task {
  id: number
  user_id: number
  title: string
  first_step: string | null
  status: TaskStatus
  created_at: string
  updated_at: string
}

interface User {
  id: number
  name: string
  email: string
}

interface LoginResponse {
  token: string
  user: User
}

interface TaskResponse {
  data: Task
}

const checkingSession = ref(Boolean(localStorage.getItem(TOKEN_KEY)))
const authenticated = ref(false)
const userName = ref('')

const email = ref('')
const password = ref('')
const loginError = ref('')
const authBusy = ref(false)

const tasks = ref<Task[]>([])
const loadingTasks = ref(false)
const busy = ref(false)
const notice = ref('')

const activeTab = ref<Tab>('active')
const selectedId = ref<number | null>(null)

const newTitle = ref('')
const newFirstStep = ref('')

const editing = ref(false)
const editTitle = ref('')
const editFirstStep = ref('')
const confirmDeleteId = ref<number | null>(null)

const visibleTasks = computed(() =>
  tasks.value.filter((task) =>
    activeTab.value === 'done'
      ? task.status === 'done'
      : task.status !== 'done',
  ),
)

const selectedTask = computed(
  () => tasks.value.find((task) => task.id === selectedId.value) ?? null,
)

const activeCount = computed(
  () => tasks.value.filter((task) => task.status !== 'done').length,
)

const doneCount = computed(
  () => tasks.value.filter((task) => task.status === 'done').length,
)

function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const response = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined

    const validationMessage = Object.values(response?.errors ?? {})[0]?.[0]

    return validationMessage
      ?? response?.message
      ?? 'Permintaan gagal. Coba lagi.'
  }

  return 'Terjadi kesalahan. Coba lagi.'
}

function resetSession() {
  localStorage.removeItem(TOKEN_KEY)
  authenticated.value = false
  userName.value = ''
  tasks.value = []
  selectedId.value = null
  password.value = ''
  editing.value = false
  notice.value = ''
}

async function loadTasks() {
  loadingTasks.value = true

  try {
    const response = await api.get<{ data: Task[] }>('/tasks')
    tasks.value = response.data.data

    if (selectedId.value !== null &&
        !tasks.value.some((task) => task.id === selectedId.value)) {
      selectedId.value = null
    }
  } catch (error) {
    notice.value = errorMessage(error)

    if (axios.isAxiosError(error) && error.response?.status === 401) {
      resetSession()
    }
  } finally {
    loadingTasks.value = false
  }
}

async function restoreSession() {
  if (!localStorage.getItem(TOKEN_KEY)) {
    checkingSession.value = false
    return
  }

  try {
    const response = await api.get<User>('/user')
    userName.value = response.data.name
    authenticated.value = true
    await loadTasks()
  } catch {
    resetSession()
  } finally {
    checkingSession.value = false
  }
}

async function logIn() {
  loginError.value = ''
  authBusy.value = true

  try {
    const response = await api.post<LoginResponse>('/login', {
      email: email.value.trim(),
      password: password.value,
    })

    localStorage.setItem(TOKEN_KEY, response.data.token)
    userName.value = response.data.user.name
    authenticated.value = true
    password.value = ''

    await loadTasks()
  } catch (error) {
    loginError.value = errorMessage(error)
  } finally {
    authBusy.value = false
  }
}

async function logOut() {
  try {
    await api.post('/logout')
  } catch {
    // Sesi lokal tetap dibersihkan jika token sudah tidak berlaku.
  } finally {
    resetSession()
  }
}

function selectTab(tab: Tab) {
  activeTab.value = tab
  selectedId.value = null
  editing.value = false
  confirmDeleteId.value = null
}

function selectTask(task: Task) {
  selectedId.value = task.id
  editTitle.value = task.title
  editFirstStep.value = task.first_step ?? ''
  editing.value = false
  confirmDeleteId.value = null
  notice.value = ''
}

async function addTask() {
  if (!newTitle.value.trim()) return

  busy.value = true
  notice.value = ''

  try {
    const response = await api.post<TaskResponse>('/tasks', {
      title: newTitle.value.trim(),
      first_step: newFirstStep.value.trim() || null,
    })

    tasks.value.unshift(response.data.data)
    activeTab.value = 'active'
    selectTask(response.data.data)

    newTitle.value = ''
    newFirstStep.value = ''
    notice.value = 'Tugas berhasil dicatat.'
  } catch (error) {
    notice.value = errorMessage(error)
  } finally {
    busy.value = false
  }
}

async function updateTask(
  task: Task,
  changes: Partial<Pick<Task, 'title' | 'first_step' | 'status'>>,
): Promise<boolean> {
  busy.value = true
  notice.value = ''

  try {
    const response = await api.patch<TaskResponse>(
      `/tasks/${task.id}`,
      changes,
    )

    tasks.value = tasks.value.map((item) =>
      item.id === task.id ? response.data.data : item,
    )

    return true
  } catch (error) {
    notice.value = errorMessage(error)
    return false
  } finally {
    busy.value = false
  }
}

async function saveEdits() {
  const task = selectedTask.value
  if (!task || !editTitle.value.trim()) return

  const saved = await updateTask(task, {
    title: editTitle.value.trim(),
    first_step: editFirstStep.value.trim() || null,
  })

  if (saved) {
    editing.value = false
    notice.value = 'Perubahan tersimpan.'
  }
}

async function changeStatus(task: Task, status: TaskStatus) {
  const saved = await updateTask(task, { status })

  if (saved) {
    activeTab.value = status === 'done' ? 'done' : 'active'
    notice.value = status === 'done'
      ? 'Satu tugas selesai. Kerja bagus.'
      : 'Status tugas diperbarui.'
  }
}

async function deleteTask(task: Task) {
  busy.value = true
  notice.value = ''

  try {
    await api.delete(`/tasks/${task.id}`)

    tasks.value = tasks.value.filter((item) => item.id !== task.id)
    selectedId.value = null
    confirmDeleteId.value = null
    notice.value = 'Tugas berhasil dihapus.'
  } catch (error) {
    notice.value = errorMessage(error)
  } finally {
    busy.value = false
  }
}

function statusLabel(status: TaskStatus): string {
  if (status === 'done') return 'SELESAI'
  if (status === 'in_progress') return 'SEDANG DIMULAI'
  return 'BELUM DIMULAI'
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(value))
}

onMounted(restoreSession)
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <div class="brand">
        <span class="brand-symbol">MD<span>+</span></span>
        <div>
          <strong>mulai.dulu</strong>
          <small>PERSONAL TASK CATALOG / 001</small>
        </div>
      </div>

      <div class="topbar-right">
        <span class="edition">EDISI HARI INI · ARSIP PRIBADI</span>
        <button
          v-if="authenticated"
          class="topbar-exit"
          type="button"
          @click="logOut"
        >
          KELUAR ↗
        </button>
      </div>
    </header>

    <main v-if="checkingSession" class="loading-screen">
      <span class="mono">MEMBUKA ARSIP...</span>
    </main>

    <main v-else-if="!authenticated" class="login-layout">
      <section class="login-intro">
        <div class="folio">CATATAN KECIL / VOL. 01</div>
        <div class="intro-art" aria-hidden="true">
          <div class="art-square one"></div>
          <div class="art-square two"></div>
          <div class="art-square three"></div>
          <span>01 →</span>
        </div>

        <h1>Yang penting,<br><em>mulai dulu.</em></h1>

        <p>
          Nggak harus selesai semua hari ini.
          Simpan yang ada di pikiran, lalu pilih satu langkah kecil.
        </p>

        <div class="intro-footer">
          <span>UNTUK HARI YANG TERASA PENUH</span>
          <span>NO. 0001</span>
        </div>
      </section>

      <section class="login-form-area">
        <form class="login-card" @submit.prevent="logIn">
          <div class="card-meta">
            <span>LEMBAR AKSES</span>
            <span>FORM / 01</span>
          </div>

          <div class="login-card-content">
            <div class="stamp">AKSES<br>PRIBADI</div>
            <h2>Balik lagi?</h2>
            <p>Masuk untuk melanjutkan dari langkah terakhir.</p>

            <label for="login-email">01 / EMAIL</label>
            <input
              id="login-email"
              v-model="email"
              type="email"
              autocomplete="email"
              placeholder="nama@email.com"
              required
            >

            <label for="login-password">02 / KATA SANDI</label>
            <input
              id="login-password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              placeholder="Masukkan kata sandi"
              required
            >

            <p v-if="loginError" class="form-error" role="alert">
              {{ loginError }}
            </p>

            <button class="primary-button" type="submit" :disabled="authBusy">
              {{ authBusy ? 'MEMERIKSA...' : 'MASUK KE ARSIP →' }}
            </button>

            <div class="perforation"></div>
            <p class="card-note">
              Satu langkah kecil tetap dihitung sebagai langkah.
            </p>
          </div>
        </form>
      </section>
    </main>

    <main v-else class="workspace">
      <aside class="sidebar">
        <div class="sidebar-caption">DAFTAR ISI / 001</div>

        <button
          class="nav-item"
          :class="{ selected: activeTab === 'active' }"
          type="button"
          @click="selectTab('active')"
        >
          <span>01 / INBOX</span>
          <b>{{ activeCount.toString().padStart(2, '0') }}</b>
        </button>

        <button
          class="nav-item"
          :class="{ selected: activeTab === 'done' }"
          type="button"
          @click="selectTab('done')"
        >
          <span>02 / SELESAI</span>
          <b>{{ doneCount.toString().padStart(2, '0') }}</b>
        </button>

        <div class="sidebar-bottom">
          <div class="user-label">ARSIP MILIK</div>
          <strong>{{ userName }}</strong>
          <p>Nggak harus sekaligus.</p>
          <div class="barcode">|||| ||| |||||| |||</div>
        </div>
      </aside>

      <section class="task-sheet">
        <div class="sheet-head">
          <div>
            <div class="eyebrow">MULAIDULU / LEMBAR KERJA 01</div>
            <h1>{{ activeTab === 'done' ? 'Yang sudah selesai.' : 'Isi kepala, di sini dulu.' }}</h1>
            <p>
              {{ activeTab === 'done'
                ? 'Hal-hal kecil yang sudah berhasil dikerjakan.'
                : 'Catat dulu saja. Urusan mulai, pilih satu nanti.' }}
            </p>
          </div>
          <span class="sheet-number">
            {{ visibleTasks.length.toString().padStart(2, '0') }} BERKAS
          </span>
        </div>

        <form
          v-if="activeTab === 'active'"
          class="new-task-form"
          @submit.prevent="addTask"
        >
          <div class="form-heading">
            <span>+ TAMBAH CATATAN</span>
            <span>ENTRY / BARU</span>
          </div>

          <label for="new-task-title">APA YANG ADA DI PIKIRAN?</label>
          <input
            id="new-task-title"
            v-model="newTitle"
            placeholder="Contoh: kerjakan laporan praktikum"
            maxlength="120"
            required
          >

          <label for="new-task-step">LANGKAH PERTAMA (OPSIONAL)</label>
          <input
            id="new-task-step"
            v-model="newFirstStep"
            placeholder="Contoh: buka dokumennya dulu"
            maxlength="500"
          >

          <button
            class="primary-button"
            type="submit"
            :disabled="busy || !newTitle.trim()"
          >
            + CATAT TUGAS
          </button>
        </form>

        <div class="list-heading">
          <span>{{ activeTab === 'done' ? 'ARSIP TUNTAS' : 'DAFTAR TUGAS' }}</span>
          <span>NO / STATUS</span>
        </div>

        <p v-if="loadingTasks" class="empty-state">Memuat daftar tugas...</p>

        <div v-else-if="visibleTasks.length === 0" class="empty-state">
          <strong>Masih kosong di sini.</strong>
          <span>
            {{ activeTab === 'done'
              ? 'Belum ada tugas yang ditandai selesai.'
              : 'Tulis satu hal yang ingin kamu mulai.' }}
          </span>
        </div>

        <div v-else class="task-list">
          <button
            v-for="(task, index) in visibleTasks"
            :key="task.id"
            type="button"
            class="task-ticket"
            :class="{ picked: selectedId === task.id }"
            @click="selectTask(task)"
          >
            <span class="ticket-index">
              {{ String(index + 1).padStart(2, '0') }}
            </span>

            <span class="ticket-content">
              <strong>{{ task.title }}</strong>
              <small>{{ task.first_step || 'Langkah pertama belum ditentukan' }}</small>
            </span>

            <span class="ticket-status" :class="'status-' + task.status">
              {{ statusLabel(task.status) }}
            </span>

            <span class="ticket-arrow">↗</span>
          </button>
        </div>

        <p v-if="notice" class="notice" role="status">{{ notice }}</p>
      </section>

      <aside class="inspector">
        <div class="inspector-head">
          <span>LEMBAR DETAIL</span>
          <span>NO. {{ selectedTask?.id ?? '---' }}</span>
        </div>

        <div v-if="!selectedTask" class="inspector-empty">
          <div class="empty-symbol">↖</div>
          <strong>Pilih satu dulu.</strong>
          <p>Klik tugas di daftar untuk melihat dan mengaturnya.</p>
        </div>

        <div v-else class="inspector-body">
          <div class="inspector-meta">
            <span>{{ statusLabel(selectedTask.status) }}</span>
            <span>{{ formatDate(selectedTask.created_at) }}</span>
          </div>

          <form v-if="editing" class="edit-form" @submit.prevent="saveEdits">
            <label for="edit-task-title">NAMA TUGAS</label>
            <textarea
              id="edit-task-title"
              v-model="editTitle"
              rows="3"
              maxlength="120"
              required
            ></textarea>

            <label for="edit-task-step">LANGKAH PERTAMA</label>
            <textarea
              id="edit-task-step"
              v-model="editFirstStep"
              rows="4"
              maxlength="500"
            ></textarea>

            <button class="primary-button" type="submit" :disabled="busy">
              SIMPAN PERUBAHAN
            </button>
            <button class="text-button" type="button" @click="editing = false">
              BATAL
            </button>
          </form>

          <template v-else>
            <div class="focus-label">TUGAS TERPILIH</div>
            <h2>{{ selectedTask.title }}</h2>

            <div class="step-box">
              <span>LANGKAH PALING KECIL</span>
              <p>
                {{ selectedTask.first_step ||
                  'Belum ada langkah pertama. Kamu boleh menambahkannya.' }}
              </p>
            </div>

            <div class="task-actions">
              <button
                v-if="selectedTask.status === 'inbox'"
                class="primary-button"
                type="button"
                :disabled="busy"
                @click="changeStatus(selectedTask, 'in_progress')"
              >
                ▷ MULAI SATU LANGKAH
              </button>

              <button
                v-if="selectedTask.status !== 'done'"
                class="outline-button"
                type="button"
                :disabled="busy"
                @click="changeStatus(selectedTask, 'done')"
              >
                ✓ TANDAI SELESAI
              </button>

              <button
                v-if="selectedTask.status === 'in_progress'"
                class="outline-button"
                type="button"
                :disabled="busy"
                @click="changeStatus(selectedTask, 'inbox')"
              >
                NANTI DULU
              </button>

              <button
                v-if="selectedTask.status === 'done'"
                class="outline-button"
                type="button"
                :disabled="busy"
                @click="changeStatus(selectedTask, 'inbox')"
              >
                ↶ KEMBALIKAN KE INBOX
              </button>

              <button class="text-button" type="button" @click="editing = true">
                UBAH CATATAN ↗
              </button>

              <button
                v-if="confirmDeleteId !== selectedTask.id"
                class="text-button destructive"
                type="button"
                @click="confirmDeleteId = selectedTask.id"
              >
                HAPUS TUGAS
              </button>

              <div v-else class="delete-confirmation">
                <p>Yakin ingin menghapus tugas ini?</p>
                <button
                  class="danger-button"
                  type="button"
                  :disabled="busy"
                  @click="deleteTask(selectedTask)"
                >
                  YA, HAPUS
                </button>
                <button
                  class="text-button"
                  type="button"
                  @click="confirmDeleteId = null"
                >
                  BATAL
                </button>
              </div>
            </div>
          </template>
        </div>

        <div class="permission-slip">
          <span>CATATAN PINGGIR / 001</span>
          <p>Mulai kecil juga tetap mulai. Kamu boleh berhenti dan kembali lagi.</p>
        </div>
      </aside>
    </main>

    <footer class="app-footer">
      <span>MULAI.DULU / PERSONAL WORKSPACE</span>
      <span>ONE SMALL STEP AT A TIME.</span>
    </footer>
  </div>
</template>
