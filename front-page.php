<?php
/**
 * Front page — the whole app. Access is gated in functions.php
 * (template_redirect): guests → login, non-admins → 403.
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="cc-shell" x-data="ccChat()">

	<!-- ============ SIDEBAR ============ -->
	<aside class="cc-side" :class="sideOpen ? '' : 'cc-side-hidden'">
		<div class="cc-side-top">
			<div class="cc-brand">
				<i data-lucide="clapperboard" class="w-5 h-5 text-emerald-600"></i>
				<span>Studio Inspirasi Chat</span>
			</div>
			<button type="button" @click="newSession()" class="cc-new">
				<i data-lucide="plus" class="w-4 h-4"></i> Chat baru
			</button>
		</div>

		<nav class="cc-sessions">
			<template x-for="(s, i) in sessions" :key="s.id">
				<div class="cc-sess"
					:class="{'cc-sess-on': s.id === activeId, 'cc-drop': dropAt === i && dragFrom !== i, 'cc-dragging': dragFrom === i}"
					draggable="true"
					@dragstart="sessDragStart(i, $event)"
					@dragover.prevent="sessDragOver(i)"
					@drop.prevent="sessDrop(i)"
					@dragend="sessDragEnd()">
					<button type="button" @click="openSession(s.id)" class="cc-sess-btn">
						<span class="truncate" x-text="s.title.replace(/-/g,' ')"></span>
						<span x-show="s.active" x-cloak class="cc-spin-dot" title="Sedang bekerja"></span>
					</button>
					<button type="button" @click="openRename(s)" class="cc-sess-act" title="Rename / hapus" aria-label="Rename / hapus">
						<i data-lucide="pencil" class="w-3.5 h-3.5"></i>
					</button>
				</div>
			</template>
			<p x-show="!sessions.length" x-cloak class="cc-sess-empty">Belum ada percakapan.</p>
		</nav>

		<div class="cc-side-foot">
			<div class="cc-status" :title="statusHint">
				<span class="cc-dot" :class="statusOpenai ? 'cc-dot-ok' : 'cc-dot-off'"></span> OpenAI
				<span class="cc-dot ml-2" :class="statusConcat ? 'cc-dot-ok' : 'cc-dot-off'"></span> Studio Inspirasi
			</div>
			<button type="button" @click="openSettings()" class="cc-set" title="Pengaturan" aria-label="Pengaturan">
				<i data-lucide="settings-2" class="w-4 h-4"></i>
			</button>
		</div>
	</aside>

	<!-- ============ MAIN ============ -->
	<main class="cc-main">
		<header class="cc-head">
			<button type="button" @click="sideOpen = !sideOpen" class="cc-burger" aria-label="Sidebar">
				<i data-lucide="panel-left" class="w-4 h-4"></i>
			</button>
			<h1 class="cc-title" x-text="mode === 'control' ? 'Control' : ((activeTitle || 'Studio Inspirasi Chat').replace(/-/g,' '))"></h1>
			<div class="cc-mode" role="tablist" aria-label="Mode">
				<button type="button" @click="setMode('chat')" :class="mode === 'chat' ? 'cc-mode-on' : ''" class="cc-mode-btn">Chat</button>
				<button type="button" @click="setMode('control')" :class="mode === 'control' ? 'cc-mode-on' : ''" class="cc-mode-btn">Control</button>
			</div>
			<span x-show="hasActive" x-cloak class="cc-working" x-text="jobDetail || 'AI sedang bekerja…'"></span>
			<div class="cc-head-act">
				<button type="button" @click="toggleDark()" class="cc-set" title="Mode gelap/terang" aria-label="Mode gelap">
					<i x-show="!dark" data-lucide="moon" class="w-4 h-4"></i>
					<i x-show="dark" x-cloak data-lucide="sun" class="w-4 h-4"></i>
				</button>
			</div>
		</header>

		<!-- ============ CONTROL VIEW ============ -->
		<div x-show="mode === 'control'" x-cloak class="cc-ctl">
			<div class="cc-ctl-card">
				<!-- Indikator langkah -->
				<div class="cc-steps" role="list">
					<template x-for="(lbl, i) in ctlStepLabels()" :key="i">
						<div class="cc-step-item" :class="ctl.step === i ? 'cc-step-on' : (ctl.step > i ? 'cc-step-done' : '')">
							<button type="button" class="cc-step" @click="ctl.step = i">
								<span class="cc-step-num">
									<span x-show="ctl.step <= i" x-text="i + 1"></span>
									<svg x-show="ctl.step > i" x-cloak viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3.5 8.5 6.5 11.5 12.5 4.5"/></svg>
								</span>
								<span class="cc-step-lbl" x-text="lbl"></span>
							</button>
							<span x-show="i < 3" class="cc-step-line" aria-hidden="true"></span>
						</div>
					</template>
				</div>

				<!-- LANGKAH 1: Sumber & Video / VO -->
				<div x-show="ctl.step === 0">
				<h4 class="cc-sec">Sumber & Video</h4>
				<label class="cc-field"><span>Jenis produksi</span>
					<select x-model="ctl.f.workflow" class="cc-input" @change="ctlSuggestName()">
						<option value="clip">Potong Video (Clipper — 1 video jadi klip pendek)</option>
						<option value="broll">Talking Head + Auto B-Roll & SFX (AI)</option>
						<option value="assembly">Rakit Penuh dari Voice Over + B-Roll & SFX (AI)</option>
						<option value="cartoon">Animasi Kartun (storyboard + sprite karakter)</option>
					</select>
				</label>

				<div class="cc-field" x-show="ctl.f.workflow !== 'cartoon'"><span>Sumber komposisi</span>
					<div class="cc-tabs">
						<button type="button" @click="ctl.f.src = 'template'" :class="ctl.f.src === 'template' && 'cc-tab-on'" class="cc-tab">Dari template</button>
						<button type="button" @click="ctl.f.src = 'baru'" :class="ctl.f.src === 'baru' && 'cc-tab-on'" class="cc-tab">Proyek baru</button>
					</div>
				</div>

				<label class="cc-field" x-show="ctl.f.src === 'template'"><span>Template</span>
					<select x-model="ctl.f.template" class="cc-input" @change="ctlTemplateChange()">
						<option value="">— pilih template —</option>
						<template x-for="t in ctl.templates" :key="t.path">
							<option :value="t.path" x-text="t.name.replace(/-/g,' ') + ' · ' + t.width + '×' + t.height"></option>
						</template>
					</select>
					<small class="cc-note" x-show="ctl.tplError" x-cloak x-text="ctl.tplError"></small>
					<small class="cc-note">Ukuran, frame, & gaya subtitle mengikuti template.</small>
				</label>

				<label class="cc-field" x-show="ctl.f.src === 'baru'"><span>Aspect ratio</span>
					<select x-model="ctl.f.aspect" class="cc-input">
						<option value="9:16">9:16 — vertikal (1080×1920)</option>
						<option value="16:9">16:9 — landscape (1920×1080)</option>
						<option value="1:1">1:1 — persegi (1080×1080)</option>
						<option value="4:5">4:5 — potrait feed (1080×1350)</option>
					</select>
				</label>

				<label class="cc-field" x-show="ctl.f.workflow === 'cartoon'" x-cloak>
					<span>Folder karakter (sprite PNG + CSV)</span>
					<div class="cc-filerow">
						<input type="text" x-model="ctl.f.chars_dir" class="cc-input" placeholder="/Users/.../characters — berisi subfolder per karakter">
						<button type="button" @click="ctlBrowseOpen('chars_dir', 'dir')" class="cc-btn">Pilih Folder</button>
					</div>
					<small class="cc-note">Tiap subfolder = satu karakter; isi PNG + <code>&lt;nama&gt;.csv</code> (file,look,ekspresi,varian,height).</small>
				</label>

				<label class="cc-field" x-show="ctl.f.workflow !== 'assembly' && ctl.f.workflow !== 'cartoon'">
					<span x-text="ctl.f.workflow === 'broll' ? 'Video utama (Talking Head)' : 'File video'"></span>
					<div class="cc-filerow">
						<input type="text" x-model="ctl.f.file" @input="ctlSuggestName()" class="cc-input" placeholder="/path/ke/video.mp4 atau URL YouTube">
						<button type="button" @click="ctlBrowseOpen('file', 'file')" class="cc-btn">Browse</button>
					</div>
					<small class="cc-note">Tempel link <code>youtube.com</code>/<code>youtu.be</code> atau pilih file video lokal.</small>
				</label>

				<label class="cc-field" x-show="ctl.f.workflow === 'assembly'" x-cloak>
					<span>File audio Voice Over (MP3/WAV)</span>
					<div class="cc-filerow">
						<input type="text" x-model="ctl.f.vo_file" @input="ctlSuggestName()" class="cc-input" placeholder="/path/ke/voiceover.mp3">
						<button type="button" @click="ctlBrowseOpen('vo_file', 'file')" class="cc-btn">Browse</button>
					</div>
					<small class="cc-note">Durasi akhir video mengikuti panjang Voice Over ini.</small>
				</label>

				</div>

				<!-- LANGKAH 2: Bahan B-Roll & Subtitle -->
				<div x-show="ctl.step === 1">
				<h4 class="cc-sec">Bahan & Subtitle</h4>
				<template x-if="ctl.f.workflow !== 'clip'">
					<div style="display:flex;flex-direction:column;gap:1rem">
						<label class="cc-field"><span>Folder koleksi video B-Roll</span>
							<div class="cc-filerow">
								<input type="text" x-model="ctl.f.broll_dir" class="cc-input" placeholder="/Users/.../Assets/broll">
								<button type="button" @click="ctlBrowseOpen('broll_dir', 'dir')" class="cc-btn">Pilih Folder</button>
							</div>
							<small class="cc-note">Taruh semua klip video bahan (.mp4/.mov) di dalam satu folder.</small>
						</label>

						<div class="cc-field"><span>Pengenalan visual B-Roll</span>
							<label class="cc-note" style="display:flex;align-items:center;gap:.4rem;margin-top:0">
								<input type="checkbox" x-model="ctl.f.broll_vision"> Scan isi visual tiap video dengan AI Vision
							</label>
							<small class="cc-note">Jika tidak dicentang, AI mengenali klip dari nama file (instan & gratis). Hasil scan Vision di-cache di folder.</small>
						</div>

						<label class="cc-field"><span>Folder Sound Effects / SFX <em class="cc-tag">opsional</em></span>
							<div class="cc-filerow">
								<input type="text" x-model="ctl.f.sfx_dir" class="cc-input" placeholder="/Users/.../Assets/sfx">
								<button type="button" @click="ctlBrowseOpen('sfx_dir', 'dir')" class="cc-btn">Pilih Folder</button>
							</div>
							<small class="cc-note">Koleksi audio transisi (.mp3/.wav) yang akan dipasang otomatis saat pergantian B-Roll.</small>
						</label>
					</div>
				</template>

				<template x-if="ctl.f.workflow === 'cartoon'">
					<div style="display:flex;flex-direction:column;gap:1rem">
						<label class="cc-field"><span>Musik latar / BGM <em class="cc-tag">opsional</em></span>
							<div class="cc-filerow">
								<input type="text" x-model="ctl.f.bgm_file" class="cc-input" placeholder="/path/ke/bgm.mp3">
								<button type="button" @click="ctlBrowseOpen('bgm_file', 'file')" class="cc-btn">Browse</button>
								<input type="number" x-model.number="ctl.f.bgm_vol" step="0.05" min="0" max="1" class="cc-input cc-input-num" title="Volume BGM (0-1)">
							</div>
							<small class="cc-note">Dipasang di track audio paling bawah sepanjang video.</small>
						</label>
						<label class="cc-field"><span>Transisi antar scene</span>
							<select x-model="ctl.f.transition" class="cc-input">
								<option value="fade">Fade</option>
								<option value="slide">Slide</option>
								<option value="pop">Pop</option>
								<option value="none">Cut (langsung)</option>
							</select>
						</label>
						<label class="cc-field"><span>Instruksi AI global <em class="cc-tag">opsional</em></span>
							<textarea x-model="ctl.f.prompt" class="cc-input" rows="2" placeholder="Gaya edukasi anak, ceria, banyak SFX…"></textarea>
						</label>
					</div>
				</template>

				<template x-if="ctl.f.workflow !== 'cartoon'">
				<div>
				<label class="cc-field"><span>Caption & transkrip bahasa</span>
					<select x-model="ctl.f.cap_lang" class="cc-input">
						<option value="id">Indonesia (auto-caption / Whisper)</option>
						<option value="en">English</option>
						<option value="auto">Deteksi otomatis</option>
						<option value="none">Tanpa subtitle</option>
					</select>
				</label>
				<label class="cc-field"><span>File .srt manual <em class="cc-tag">opsional</em></span>
					<div class="cc-filerow">
						<input type="text" x-model="ctl.f.srt" class="cc-input" placeholder="/path/ke/subtitle.srt — override transkrip otomatis">
						<button type="button" @click="ctlBrowseOpen('srt', 'file')" class="cc-btn">Browse</button>
					</div>
					<small class="cc-note">Kosongkan untuk memakai subtitle YouTube atau transkrip Whisper otomatis.</small>
				</label>
				</div>
				</template>

				</div>

				<!-- LANGKAH 3: Segmen, Potong & Arahan AI -->
				<div x-show="ctl.step === 2">
				<h4 class="cc-sec" x-text="ctl.f.workflow === 'cartoon' ? 'Storyboard' : 'Segmen & Arahan AI'"></h4>
				<template x-if="ctl.f.workflow === 'cartoon'">
					<div style="display:flex;flex-direction:column;gap:.8rem">
						<div class="cc-field" style="text-align:center;padding:1.2rem">
							<strong x-text="ctl.f.scenes.length + ' scene · ' + ctl.f.scenes.reduce((a,s)=>a+s.rows.length,0) + ' baris aksi'"></strong><br>
							<small class="cc-note">Storyboard terlalu besar untuk wizard — edit di layar penuh.</small><br>
							<button type="button" @click="ctl.sb = true" class="cc-btn cc-btn-primary" style="margin-top:.7rem">
								<i data-lucide="layout-list" class="w-4 h-4"></i> Buka Storyboard Editor
							</button>
						</div>
						<small class="cc-note">Baris <b>bicara</b>: audio dialog + teks caption — flap mulut otomatis. <b>jalan</b>: walk-cycle + SFX langkah.</small>
					</div>
				</template>
				<template x-if="['clip','broll'].includes(ctl.f.workflow)">
					<div style="display:flex;flex-direction:column;gap:1rem">
						<div class="cc-field"><span>Segmen <em class="cc-tag">1 segmen = 1 proyek</em></span>
							<select x-model="ctl.f.mode" class="cc-input" style="margin-bottom:.55rem">
								<option value="manual">Manual — tentukan rentang waktu</option>
								<option value="moments">Otomatis — momen terbaik (AI)</option>
							</select>
							<div x-show="ctl.f.mode === 'moments'" class="cc-inline-row">
								<input type="number" x-model.number="ctl.f.moment_count" min="1" max="10" class="cc-input cc-input-num" title="Jumlah momen">
								<span class="cc-note">momen · durasi</span>
								<input type="number" x-model.number="ctl.f.moment_min" min="5" class="cc-input cc-input-num" title="Durasi minimum (detik)">
								<span class="cc-note">–</span>
								<input type="number" x-model.number="ctl.f.moment_max" min="10" class="cc-input cc-input-num" title="Durasi maksimum (detik)">
								<span class="cc-note">detik</span>
							</div>
							<template x-if="ctl.f.mode === 'manual'">
								<div>
									<template x-for="(s, i) in ctl.f.segments" :key="i">
										<div class="cc-segrow">
											<input type="text" x-model="s.start" class="cc-input cc-input-time" placeholder="2:30" inputmode="numeric">
											<span class="cc-note">s/d</span>
											<input type="text" x-model="s.end" class="cc-input cc-input-time" placeholder="3:32" inputmode="numeric">
											<button type="button" @click="ctl.f.segments.splice(i, 1)" x-show="ctl.f.segments.length > 1" class="cc-set" title="Hapus segmen" aria-label="Hapus segmen">
												<i data-lucide="x" class="w-4 h-4"></i>
											</button>
										</div>
									</template>
									<button type="button" @click="ctl.f.segments.push({start: '', end: ''})" class="cc-btn cc-btn-ghost cc-btn-sm">
										<i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah segmen
									</button>
								</div>
							</template>
							<small class="cc-note">Manual: format <code>m:ss</code>. Otomatis: AI pilih momen dari transkrip — judul dipakai sebagai nama proyek.</small>
						</div>

						<div class="cc-field"><span>Potong</span>
							<label class="cc-note" style="display:flex;align-items:center;gap:.4rem;margin-top:0">
								<input type="checkbox" x-model="ctl.f.silence_cut"> Potong jeda hening
							</label>
							<div x-show="ctl.f.silence_cut" class="cc-inline-row">
								<input type="number" x-model.number="ctl.f.silence_db" step="1" class="cc-input cc-input-num" title="Threshold dB">
								<span class="cc-note">dB ·</span>
								<input type="number" x-model.number="ctl.f.silence_min" step="0.1" min="0.2" class="cc-input cc-input-num" title="Min hening (detik)">
								<span class="cc-note">s hening · pad</span>
								<input type="number" x-model.number="ctl.f.silence_pad" step="0.05" class="cc-input cc-input-num" title="Padding (detik)">
								<span class="cc-note">s</span>
							</div>
							<small class="cc-note">Buang bagian tanpa suara di tiap segmen — konten lebih rapat.</small>
						</div>
					</div>
				</template>

				<template x-if="['broll','assembly'].includes(ctl.f.workflow)">
					<div style="display:flex;flex-direction:column;gap:1rem">
						<label class="cc-field"><span>Ritme potongan B-Roll (Pacing)</span>
							<select x-model="ctl.f.broll_pacing" class="cc-input">
								<option value="calm">Santai (tiap 4–6.5 detik)</option>
								<option value="sentence">Mengikuti kalimat / topik (3–6 detik)</option>
								<option value="dynamic">Dinamis & cepat (tiap 2–4 detik)</option>
							</select>
						</label>
						<div class="cc-row2">
							<label class="cc-field"><span>Animasi masuk B-Roll</span>
								<select x-model="ctl.f.broll_motion_in" class="cc-input">
									<option value="slide">Slide — geser masuk (default)</option>
									<option value="fade">Fade — muncul memudar</option>
									<option value="pop">Pop — zoom cepat</option>
									<option value="none">Tanpa animasi</option>
								</select>
							</label>
							<label class="cc-field"><span>Animasi keluar B-Roll</span>
								<select x-model="ctl.f.broll_motion_out" class="cc-input">
									<option value="fade">Fade — keluar memudar (default)</option>
									<option value="slide">Slide — geser keluar</option>
									<option value="pop">Pop — zoom keluar</option>
									<option value="none">Tanpa animasi</option>
								</select>
							</label>
						</div>
						<small class="cc-note">Durasi animasi 0.3 detik. SFX & video utama tidak terpengaruh.</small>
						<label class="cc-field"><span>Instruksi untuk AI Director <em class="cc-tag">opsional</em></span>
							<textarea x-model="ctl.f.broll_prompt" rows="2" class="cc-input" placeholder="Misal: tampilkan B-roll alam saat membahas ketenangan, gunakan sfx whoosh di tiap transisi…"></textarea>
							<small class="cc-note">B-Roll otomatis di-fit memenuhi kanvas (fill) dan audionya di-mute agar suara utama jernih.</small>
						</label>
						<label class="cc-check"><input type="checkbox" x-model="ctl.f.edl_review">
							<span><strong>Review rencana B-Roll sebelum dipasang</strong><br>
							<small class="cc-note">Pipeline berjeda setelah AI menyusun sisipan — Anda bisa melihat preview tiap klip, mengedit, lalu melanjutkan rakitan.</small></span>
						</label>
					</div>
				</template>

				<template x-if="ctl.f.src === 'template' && ctl.f.workflow !== 'cartoon'">
					<div class="cc-field"><span>Adaptasi template</span>
						<label class="cc-note" style="display:flex;align-items:center;gap:.4rem;margin-top:0">
							<input type="checkbox" x-model="ctl.f.hook_ai"> Tulis ulang teks hook dengan AI
						</label>
						<small class="cc-note">Overlay template otomatis menyesuaikan durasi segmen — CTA menempel di akhir, frame ikut memanjang/memendek. Hook ditulis ulang dari transkrip (elemen CTA tidak disentuh).</small>
					</div>
				</template>

				</div>

				<!-- LANGKAH 4: Proyek & Aksi -->
				<div x-show="ctl.step === 3">
				<h4 class="cc-sec">Proyek & Aksi</h4>
				<label class="cc-field"><span>Nama proyek</span>
					<input type="text" x-model="ctl.f.name" class="cc-input" placeholder="reel-baru-01">
				</label>

				<div class="cc-field"><span>Aksi akhir</span>
					<label class="cc-radio"><input type="radio" value="handoff" x-model="ctl.f.action">
						Simpan & serah ke Studio Inspirasi — ekspor manual di aplikasi</label>
					<label class="cc-radio"><input type="radio" value="export" x-model="ctl.f.action">
						Langsung export via API</label>
				</div>

				<template x-if="ctl.f.action === 'export'">
					<div class="cc-row2">
						<label class="cc-field"><span>Nama output</span>
							<input type="text" x-model="ctl.f.output_name" class="cc-input" placeholder="reel.mp4">
						</label>
						<label class="cc-field"><span>Salin ke (opsional)</span>
							<select x-model="ctl.f.dest_dir" class="cc-input">
								<option value="">— tidak disalin —</option>
								<template x-for="r in ctl.copyRoots" :key="r">
									<option :value="r" x-text="r"></option>
								</template>
							</select>
							<small class="cc-note" x-show="!ctl.copyRoots.length" x-cloak>Kosong — atur folder tujuan di Settings.</small>
						</label>
					</div>
				</template>
				</div>

				<p x-show="ctl.error" x-cloak x-text="ctl.error" class="cc-err"></p>

				<div class="cc-ctl-act">
					<button type="button" @click="ctl.step--" x-show="ctl.step > 0" x-cloak class="cc-btn cc-btn-ghost">← Kembali</button>
					<button type="button" @click="ctl.step++" x-show="ctl.step < 3" x-cloak class="cc-btn cc-btn-primary">Lanjut →</button>
					<button type="button" @click="submitControl()" :disabled="ctl.submitting || hasActive" x-show="ctl.step === 3" x-cloak class="cc-btn cc-btn-primary">
						<i x-show="ctl.submitting" x-cloak data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i>
						Jalankan
					</button>
					<span x-show="ctl.running" x-cloak class="cc-run-status">
						<span class="cc-spin-dot"></span>
						<span x-text="jobDetail || 'Sedang berjalan…'"></span>
						<a href="#" @click.prevent="setMode('chat')" x-show="ctl.sessionId" x-cloak>lihat detail →</a>
					</span>
				</div>
			</div>
		</div>

		<!-- Thread -->
		<div id="cc-thread" class="cc-thread" x-show="mode === 'chat'" @scroll.passive="onScroll()">
			<div class="cc-thread-in">
				<template x-if="hasMore">
					<button type="button" @click="loadOlder()" class="cc-older" :disabled="_loadingOlder"
						x-text="_loadingOlder ? 'Memuat…' : 'Muat pesan lebih lama'"></button>
				</template>
				<template x-if="!messages.length && !loading">
					<div class="cc-hero">
						<i data-lucide="clapperboard" class="w-10 h-10 mx-auto text-emerald-400"></i>
						<h2>Apa yang mau kamu edit?</h2>
						<p>Contoh: <em>"Gabungkan semua video di D:\kegiatan jadi satu reel, lalu simpan ke D:\kegiatan\dokumentasi"</em></p>
						<p class="text-xs opacity-60">AI akan membuat proyek, mengimpor media, menyusun timeline, dan mengekspor lewat Studio Inspirasi.</p>
					</div>
				</template>

				<template x-for="m in messages" :key="m.id">
					<div>
						<!-- assistant placeholder while queued -->
						<template x-if="m.role === 'assistant' && (m.status === 'queued' || m.status === 'running')">
							<div class="cc-msg cc-msg-ai"><div class="cc-bubble cc-bubble-ai cc-thinking">
								<span></span><span></span><span></span>
							</div></div>
						</template>
						<template x-if="!(m.role === 'assistant' && (m.status === 'queued' || m.status === 'running'))">
							<div x-html="m.html"></div>
						</template>
					</div>
				</template>
			</div>
		</div>

		<!-- Composer -->
		<footer class="cc-composer" x-show="mode === 'chat'">
			<p x-show="error" x-cloak x-text="error" class="cc-err"></p>
			<div class="cc-box">
				<button type="button" class="cc-plus" title="Lampiran (segera)" disabled aria-label="Lampiran">
					<i data-lucide="plus" class="w-4 h-4"></i>
				</button>
				<textarea
					x-model="body"
					x-ref="composerTa"
					@input="autoGrow($event)"
					@keydown.enter.exact.prevent="send()"
					rows="1"
					:placeholder="hasActive ? 'AI sedang bekerja — tunggu atau batalkan…' : 'Ketik perintah edit… (Enter untuk kirim)'"
					:disabled="hasActive || sending"
					class="cc-input"
				></textarea>
				<button x-show="!hasActive" type="button" @click="send()" :disabled="sending || !body.trim()" class="cc-send" title="Kirim" aria-label="Kirim">
					<i x-show="!sending" data-lucide="send" class="w-4 h-4"></i>
					<i x-show="sending" x-cloak data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>
				</button>
				<button x-show="hasActive" x-cloak type="button" @click="cancel()" class="cc-send cc-stop" title="Batalkan" aria-label="Batalkan">
					<i data-lucide="square" class="w-4 h-4"></i>
				</button>
			</div>
			<p class="cc-hint">Studio Inspirasi Chat mengirim perintahmu ke OpenAI, lalu mengendalikan Studio Inspirasi di komputer ini. Periksa kembali hasil edit di aplikasi.</p>
		</footer>
	</main>

	<!-- ============ MODAL: KONFIRMASI UMUM ============ -->
	<div x-show="st.confirm" x-cloak class="cc-ovl" style="z-index: 60" x-transition.opacity @click.self="st.confirm = ''" @keydown.escape.window="st.confirm = ''">
		<div class="cc-modal">
			<h3 class="cc-modal-title">Konfirmasi</h3>
			<p class="cc-note" x-text="st.confirm"></p>
			<div class="cc-modal-act">
				<span class="cc-flex1"></span>
				<button type="button" @click="st.confirm = ''" class="cc-btn cc-btn-ghost">Batal</button>
				<button type="button" @click="confirmYes()" class="cc-btn cc-btn-danger">
					<i data-lucide="check" class="w-3.5 h-3.5"></i> Ya, lanjutkan
				</button>
			</div>
		</div>
	</div>

	<!-- ============ MODAL: RENAME / HAPUS ============ -->
	<div x-show="modal.open" x-cloak class="cc-ovl" x-transition.opacity @click.self="modal.open = false" @keydown.escape.window="modal.open = false">
		<div class="cc-modal">
			<h3 class="cc-modal-title" x-text="modal.confirm ? 'Hapus percakapan?' : 'Rename percakapan'"></h3>

			<template x-if="!modal.confirm">
				<div>
					<input type="text" x-model="modal.title" maxlength="200" class="cc-input" placeholder="Judul percakapan" @keydown.enter="saveRename()">
					<div class="cc-modal-act">
						<button type="button" @click="askDelete()" :disabled="modal.busy" class="cc-btn cc-btn-danger">
							<i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Hapus
						</button>
						<span class="cc-flex1"></span>
						<button type="button" @click="modal.open = false" class="cc-btn cc-btn-ghost">Batal</button>
						<button type="button" @click="saveRename()" :disabled="modal.busy || !modal.title.trim()" class="cc-btn">
							<i x-show="modal.busy" x-cloak data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Simpan
						</button>
					</div>
				</div>
			</template>

			<template x-if="modal.confirm">
				<div>
					<p class="cc-note">Percakapan <strong x-text="modal.title"></strong> beserta seluruh isinya akan dihapus permanen.</p>
					<div class="cc-modal-act">
						<span class="cc-flex1"></span>
						<button type="button" @click="modal.confirm = false" :disabled="modal.busy" class="cc-btn cc-btn-ghost">Kembali</button>
						<button type="button" @click="confirmDelete()" :disabled="modal.busy" class="cc-btn cc-btn-danger">
							<i x-show="!modal.busy" data-lucide="trash-2" class="w-3.5 h-3.5"></i>
							<i x-show="modal.busy" x-cloak data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Ya, hapus
						</button>
					</div>
				</div>
			</template>
		</div>
	</div>

	<!-- ============ MODAL: FILE / FOLDER BROWSER ============ -->
	<!-- Storyboard Editor fullscreen -->
	<div x-show="ctl.sb" x-cloak class="cc-ovl cc-sb" x-transition.opacity @keydown.escape.window="ctl.sb = false">
		<div class="cc-sb-head">
			<h3><i data-lucide="layout-list" class="w-4 h-4"></i> Storyboard Editor</h3>
			<span class="cc-note" x-text="ctl.f.scenes.length + ' scene · ' + ctl.f.scenes.reduce((a,s)=>a+s.rows.length,0) + ' baris'"></span>
			<button type="button" @click="ctl.f.scenes.push({bg:'',chars:[],look:{},prompt:'',rows:[{char:'',action:'bicara',dur:2,audio:'',text:'',dir:'kanan',x:0,x2:0}]})" class="cc-btn cc-btn-sm">
				<i data-lucide="plus" class="w-3.5 h-3.5"></i> Scene
			</button>
			<button type="button" @click="ctl.sb = false" class="cc-btn cc-btn-primary cc-btn-sm">Selesai</button>
		</div>
		<div class="cc-sb-body">
			<template x-for="(sc, si) in ctl.f.scenes" :key="si">
				<div class="cc-sb-scene">
					<div class="cc-sb-scene-head">
						<strong x-text="'Scene ' + (si+1)"></strong>
						<button type="button" @click="ctl.f.scenes.splice(si,1)" x-show="ctl.f.scenes.length>1" class="cc-set" title="Hapus scene"><i data-lucide="x" class="w-4 h-4"></i></button>
					</div>
					<div class="cc-sb-meta">
						<input type="text" x-model="sc.bg" class="cc-input" placeholder="Latar: /path/gambar.png" style="flex:1">
						<button type="button" @click="ctlBrowseOpen('scene_bg_'+si, 'file')" class="cc-btn cc-btn-sm">Browse</button>
						<input type="text" x-model="sc.prompt" class="cc-input" placeholder="Instruksi scene (opsional)…" style="flex:1.4">
					</div>
					<div class="cc-sb-meta">
						<span class="cc-note">Karakter:</span>
						<template x-for="cn in ctl.f.scene_chars" :key="cn">
							<label class="cc-note cc-sb-chip" :class="sc.chars.includes(cn) && 'cc-sb-chip-on'">
								<input type="checkbox" :checked="sc.chars.includes(cn)" @change="sc.chars.includes(cn) ? sc.chars.splice(sc.chars.indexOf(cn),1) : sc.chars.push(cn)" style="display:none">
								<span x-text="cn"></span>
							</label>
						</template>
						<input type="text" x-model="ctl.new_char" placeholder="+ karakter" class="cc-input" style="width:8rem" @keydown.enter.prevent="if(ctl.new_char && !ctl.f.scene_chars.includes(ctl.new_char)){ctl.f.scene_chars.push(ctl.new_char); ctl.new_char=''}">
					</div>
					<div class="cc-sb-rows">
						<template x-for="(r, ri) in sc.rows" :key="ri">
							<div class="cc-sb-row">
								<span class="cc-note" x-text="ri+1" style="width:1.2rem;text-align:right"></span>
								<select x-model="r.char" class="cc-input" style="width:7rem">
									<template x-for="cn in sc.chars" :key="cn"><option :value="cn" x-text="cn"></option></template>
								</select>
								<select x-model="r.action" class="cc-input" style="width:7rem">
									<option value="bicara">bicara</option><option value="diam">diam</option>
									<option value="jalan">jalan</option><option value="zoom">zoom</option>
									<option value="masuk">masuk</option><option value="keluar">keluar</option>
								</select>
								<template x-if="r.action === 'bicara'">
									<span style="display:flex;gap:.4rem;flex:1">
										<input type="text" x-model="r.audio" class="cc-input" placeholder="audio dialog.mp3" title="File audio dialog" style="flex:1.2">
										<input type="text" x-model="r.text" class="cc-input" placeholder="teks dialog (caption)" title="Teks caption" style="flex:1">
									</span>
								</template>
								<template x-if="r.action === 'jalan'">
									<span style="display:flex;gap:.4rem;align-items:center;flex:1">
										<select x-model="r.dir" class="cc-input" style="width:6rem"><option value="kanan">→ kanan</option><option value="kiri">← kiri</option></select>
										<input type="number" x-model.number="r.dur" step="0.5" min="0.5" class="cc-input" style="width:5rem" title="Durasi (s)"><span class="cc-note">s</span>
									</span>
								</template>
								<template x-if="['diam','masuk','keluar','zoom'].includes(r.action)">
									<span style="display:flex;gap:.4rem;align-items:center;flex:1">
										<input type="number" x-model.number="r.dur" step="0.5" min="0.5" class="cc-input" style="width:5rem" title="Durasi (s)"><span class="cc-note">detik</span>
									</span>
								</template>
								<button type="button" @click="sc.rows.splice(ri,1)" class="cc-set" title="Hapus baris"><i data-lucide="x" class="w-4 h-4"></i></button>
							</div>
						</template>
					</div>
					<button type="button" @click="sc.rows.push({char: sc.chars[0]||'', action:'bicara', dur:2, audio:'', text:'', dir:'kanan', x:0, x2:0})" class="cc-btn cc-btn-ghost cc-btn-sm" style="margin-top:.4rem">
						<i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah baris
					</button>
				</div>
			</template>
			<small class="cc-note">Baris <b>bicara</b>: audio + teks caption, flap mulut otomatis. <b>jalan</b>: walk-cycle + SFX langkah sinkron. Karakter dari folder yang dipilih di Langkah 1.</small>
		</div>
	</div>

	<div x-show="ctl.browseOpen" x-cloak class="cc-ovl" style="z-index: 80" x-transition.opacity @click.self="ctl.browseOpen = false" @keydown.escape.window="ctl.browseOpen = false">
		<div class="cc-modal cc-modal-lg">
			<div class="flex items-center justify-between mb-3">
				<h3 class="cc-modal-title" x-text="ctl.browseMode === 'dir' ? 'Pilih folder' : 'Pilih file media'"></h3>
				<button type="button" @click="ctl.browseOpen = false" class="cc-set" aria-label="Tutup"><i data-lucide="x" class="w-4 h-4"></i></button>
			</div>
			<div class="cc-browse-path">
				<button type="button" @click="ctlBrowseTo(ctl.browse.parent)" :disabled="!ctl.browse.parent" class="cc-set" title="Naik" aria-label="Naik">
					<i data-lucide="arrow-up" class="w-4 h-4"></i>
				</button>
				<span x-text="ctl.browse.path" class="cc-browse-cur"></span>
				<button type="button" x-show="ctl.browseMode === 'dir'" x-cloak @click="ctlPickDir()" class="cc-btn cc-btn-primary cc-btn-sm">
					Gunakan Folder Ini
				</button>
			</div>
			<div class="cc-browse-list">
				<template x-for="d in ctl.browse.dirs" :key="d.path">
					<button type="button" @click="ctlBrowseTo(d.path)" class="cc-browse-item">
						<i data-lucide="folder" class="w-4 h-4"></i><span x-text="d.name"></span>
					</button>
				</template>
				<template x-for="f in ctl.browse.files" :key="f.path">
					<button type="button" @click="ctl.browseMode === 'dir' ? ctlPickDir() : ctlPick(f.path)" class="cc-browse-item cc-browse-file">
						<i data-lucide="file-video" class="w-4 h-4"></i><span x-text="f.name"></span>
						<span class="cc-browse-size" x-text="ctlSize(f.size)"></span>
					</button>
				</template>
				<p x-show="!ctl.browse.dirs.length && !ctl.browse.files.length" x-cloak class="cc-note">Tidak ada subfolder atau file media di folder ini.</p>
			</div>
		</div>
	</div>

	<!-- ============ MODAL: SETTINGS ============ -->
	<div x-show="st.open" x-cloak class="cc-ovl" x-transition.opacity @click.self="st.open = false" @keydown.escape.window="st.open = false">
		<div class="cc-modal cc-modal-lg">
			<div class="flex items-center justify-between mb-3">
				<h3 class="cc-modal-title">Pengaturan</h3>
				<button type="button" @click="st.open = false" class="cc-set" aria-label="Tutup"><i data-lucide="x" class="w-4 h-4"></i></button>
			</div>

			<div class="cc-tabs">
				<template x-for="t in [['ai','Agen AI'],['concat','Studio Inspirasi'],['folder','Folder'],['templates','Template']]">
					<button type="button" @click="st.tab = t[0]" :class="st.tab === t[0] && 'cc-tab-on'" class="cc-tab" x-text="t[1]"></button>
				</template>
			</div>
			<p x-show="st.notice" x-cloak x-text="st.notice" class="cc-notice" :class="st.noticeOk ? 'cc-notice-ok' : 'cc-notice-err'"></p>

			<!-- TAB: OpenAI -->
			<div x-show="st.tab === 'ai'" class="cc-tabbody">
				<label class="cc-field"><span>AI Provider</span>
					<select x-model="st.f.ai_provider" class="cc-input">
						<template x-for="(p, k) in st.providers" :key="k">
							<option :value="k" x-text="p.label + (p.note ? ' — ' + p.note : '')"></option>
						</template>
					</select>
				</label>
				<label class="cc-field" x-show="st.providers[st.f.ai_provider] && st.providers[st.f.ai_provider].key"><span>API Key <em x-show="st.keySet" x-cloak class="cc-tag">tersimpan</em></span>
					<input type="password" x-model="st.f.ai_key" class="cc-input" :placeholder="st.keySet ? '•••••••• (isi untuk mengganti)' : 'sk-…'" autocomplete="new-password">
				</label>
				<label class="cc-field"><span>Model</span>
					<input type="text" x-model="st.f.ai_model" class="cc-input" :placeholder="st.providers[st.f.ai_provider] ? st.providers[st.f.ai_provider].model : 'gpt-4o-mini'">
				</label>
				<label class="cc-field" x-show="st.f.ai_provider === 'custom'"><span>Base URL</span>
					<input type="text" x-model="st.f.ai_baseurl" class="cc-input" placeholder="https://api.example.com/v1">
				</label>
				<label class="cc-field"><span>System prompt <em class="cc-tag">kosong = bawaan</em></span>
					<textarea x-model="st.f.openai_prompt" rows="5" class="cc-input"></textarea>
				</label>
				<div class="cc-test">
					<button type="button" @click="testConn('openai')" :disabled="st.testing === 'openai'" class="cc-btn">
						<i x-show="st.testing !== 'openai'" data-lucide="zap" class="w-3.5 h-3.5"></i>
						<i x-show="st.testing === 'openai'" x-cloak data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i>
						<span x-text="st.testing === 'openai' ? 'Mengetes…' : 'Test koneksi OpenAI'"></span>
					</button>
					<p x-show="st.testResult.openai" x-cloak class="cc-test-res"
						:class="st.testResult.openai && st.testResult.openai.ok ? 'cc-notice-ok' : 'cc-notice-err'"
						x-text="st.testResult.openai ? ((st.testResult.openai.ok ? '✓ ' : '✗ ') + st.testResult.openai.message) : ''"></p>
				</div>
			</div>

			<!-- TAB: Studio Inspirasi -->
			<div x-show="st.tab === 'concat'" class="cc-tabbody">
				<label class="cc-field"><span>Mode koneksi</span>
					<select x-model="st.f.concat_mode" class="cc-input">
						<option value="auto">Auto — baca automation.json</option>
						<option value="manual">Manual — address + token</option>
					</select>
				</label>
				<p class="cc-note" x-text="st.automation"></p>
				<template x-if="st.f.concat_mode === 'manual'">
					<div>
						<label class="cc-field"><span>Address</span>
							<input type="text" x-model="st.f.concat_addr" class="cc-input" placeholder="127.0.0.1:7420">
						</label>
						<label class="cc-field"><span>Token <em x-show="st.tokenSet" x-cloak class="cc-tag">tersimpan</em></span>
							<input type="password" x-model="st.f.concat_token" class="cc-input" :placeholder="st.tokenSet ? '•••••••• (isi untuk mengganti)' : 'min. 20 karakter'" autocomplete="new-password">
						</label>
					</div>
				</template>
				<div class="cc-test">
					<button type="button" @click="testConn('concat')" :disabled="st.testing === 'concat'" class="cc-btn">
						<i x-show="st.testing !== 'concat'" data-lucide="zap" class="w-3.5 h-3.5"></i>
						<i x-show="st.testing === 'concat'" x-cloak data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i>
						<span x-text="st.testing === 'concat' ? 'Mengetes…' : 'Test koneksi Studio Inspirasi'"></span>
					</button>
					<p x-show="st.testResult.concat" x-cloak class="cc-test-res"
						:class="st.testResult.concat && st.testResult.concat.ok ? 'cc-notice-ok' : 'cc-notice-err'"
						x-text="st.testResult.concat ? ((st.testResult.concat.ok ? '✓ ' : '✗ ') + st.testResult.concat.message) : ''"></p>
				</div>
			</div>

			<!-- TAB: Folder -->
			<div x-show="st.tab === 'folder'" class="cc-tabbody">
				<label class="cc-field"><span>Workspace (proyek & export sementara)</span>
					<input type="text" x-model="st.f.workspace" class="cc-input">
					<small class="cc-note">Harus di bawah home folder — batasan tulis Automation.</small>
				</label>
				<label class="cc-field"><span>Folder tujuan diizinkan (pisahkan dengan ;)</span>
					<textarea x-model="st.f.copy_roots" rows="3" class="cc-input" placeholder="D:\kegiatan;D:\kegiatan\dokumentasi"></textarea>
				</label>
				<label class="cc-field"><span>Maks. iterasi tool per perintah</span>
					<input type="number" x-model="st.f.max_iter" min="1" max="50" class="cc-input cc-input-sm">
				</label>
			</div>

			<!-- TAB: Template -->
			<div x-show="st.tab === 'templates'" class="cc-tabbody">
				<div x-show="!st.templates.length" class="cc-note" style="padding:.5rem 0">Memuat template…</div>
				<template x-for="t in st.templates" :key="t.path">
					<div class="cc-filerow" style="gap:.5rem;margin-bottom:.5rem">
						<template x-if="st.renaming === t.path">
							<input type="text" x-model="st.renameVal" class="cc-input" @keydown.enter="renameTemplate(t)">
						</template>
						<template x-if="st.renaming !== t.path">
							<span class="cc-flex1" style="font-size:.85rem" x-text="t.name"></span>
						</template>
						<button type="button" class="cc-set" :title="st.renaming === t.path ? 'Simpan nama' : 'Rename'"
							@click="st.renaming === t.path ? renameTemplate(t) : (st.renaming = t.path, st.renameVal = t.name)">
							<i :data-lucide="st.renaming === t.path ? 'check' : 'pencil'" class="w-3.5 h-3.5"></i>
						</button>
						<button type="button" class="cc-set" title="Hapus template" @click="deleteTemplate(t)"
							style="color:#dc2626">
							<i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
						</button>
					</div>
				</template>
			</div>

			<div class="cc-modal-act">
				<span class="cc-flex1"></span>
				<button type="button" @click="st.open = false" class="cc-btn cc-btn-ghost">Tutup</button>
				<button type="button" @click="saveSettings()" :disabled="st.saving" class="cc-btn">
					<i x-show="st.saving" x-cloak data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Simpan
				</button>
			</div>
		</div>
	</div>
</div>
<?php get_footer(); ?>
