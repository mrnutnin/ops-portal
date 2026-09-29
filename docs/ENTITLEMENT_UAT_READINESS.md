# MintERP entitlement sync — หลักฐาน local UAT (ยังไม่อนุมัติ production)

เอกสารนี้บันทึก preflight และ **ผลส่งครั้งแรกใน local เท่านั้น** ไม่ใช่หลักฐานว่า production พร้อม และไม่อนุญาตให้ใช้ Instance ลูกค้าจริง. ใช้คู่กับ `ENTITLEMENT_SYNC_V1.md`, `new-erp/MINT_ERP_SUBSCRIPTION_CHECKLIST.md` และ runbook `new-erp/docs/operations/minterp-instance-runbook.md`. Ops ไม่ต่อฐานข้อมูล ERP โดยตรง; ผู้ใช้เป็นผู้ทำ browser/MySQL/API UAT หลังทีมงานเตรียมสภาพแวดล้อม.

## ผล migration บน local (2026-09-28 04:57 UTC)

| ระบบ | Connection ที่แอปเลือก | Migration ที่อนุมัติ | สถานะหลังรัน |
|---|---|---|---|
| MintERP `new-erp` | `local` / MySQL / ฐาน `test` | `database/migrations/2026_09_26_130000_add_sync_revision_to_instance_entitlements.php` | Ran [8], คอลัมน์ครบ; receiver ยังปิด |
| Ops `ops-portal` | `local` / MySQL / ฐาน `ops_portal` | `database/migrations/2026_09_26_170000_create_instance_sync_credentials_table.php` | Ran [6], คอลัมน์ครบ; credential 0 แถว, allowed hosts ว่าง |

เจ้าของยืนยันว่าทั้งสองฐานเป็น MySQL local ไม่มีข้อมูลลูกค้าจริง และแจ้งว่า backup ด้วยมือแล้วก่อนอนุมัติ migration สองไฟล์ (2026-09-28); ทีมพัฒนา **ไม่ได้ตรวจไฟล์ backup หรือทดสอบ restore**. หลัง apply ตรวจ migration status/schema และรัน Unit/Contract SQLite; ณ เวลา preflight นี้ยังไม่ได้สร้าง secret, bind, เปิด receiver หรือส่ง snapshot (ดูผล local UAT ด้านล่าง). ทั้งสอง repo ยังมีงานที่ไม่ได้ commit; ทบทวน diff/ไฟล์ที่ตั้งใจ deploy ก่อนขั้นถัดไป. สถานะนี้อาจเปลี่ยนได้: **ตรวจซ้ำบนเครื่องและ connection เป้าหมายทุกครั้งก่อนดำเนินการ**.

## สภาพคู่ Instance ก่อนเชื่อม local (2026-09-28 05:15 UTC; อ่านอย่างเดียว)

- ERP ฐาน `test`, entitlement `id=1`: `SUBSCRIPTION` / `UAT-QUOTA-TEST` v1 / `ACTIVE`, quota 1/3/2, ไม่เปิด Production, local revision 2; **ยังไม่ผูก `instance_ref`**, ไม่มี source revision หรือประวัติ Trial.
- Ops ฐาน `ops_portal`, MintERP Instance `id=1` (`instance_ref=01a0e58b-d861-70c2-ab02-7306f087ff86`): `SUBSCRIPTION` / `BUSINESS` v2026.1 / `ACTIVE`, source revision 2, quota 15/3/6, CRM+Asset, **เปิด Production**, เคยออก Trial 1 ครั้ง; ยังไม่มี enrollment.
- **เจ้าของเลือกทาง A:** ใช้ ERP ฐาน `test` เดิม และยอมรับว่า snapshot `ACTIVE` จะเปลี่ยนสิทธิ์เดิม (quota/CRM/Asset/Production) โดย **ไม่ส่งประวัติ Trial ย้อนหลัง**; คู่นี้จึงไม่ใช่หลักฐาน UAT ลำดับ Trial→paid. ยังไม่ใช่คำสั่งให้ทีมพัฒนาสร้าง secret, bind, เปิด receiver หรือ push แทนผู้ใช้. ณ เวลาตรวจนี้ API receiver ERP ยังปิด; loopback HTTP ใช้ได้เฉพาะ Ops local และต้องให้ผู้ใช้ทำ integration UAT เอง.

## ผล local UAT ที่ผู้ใช้ทำ (2026-09-28 05:53 UTC)

- ผู้ใช้จับคู่และส่งผ่านหน้า Ops Instance ไป ERP `http://127.0.0.1:8000`: ความพยายามแรกได้ HTTP 404 ขณะ receiver ปิด; หลังผู้ใช้เปิด receiver เฉพาะ ERP local และส่งซ้ำ ERP ตอบ `applied` ที่ source revision 2. Ops audit ล่าสุดเป็น `instance.sync.delivered` revision 2.
- ตรวจ ERP ฐาน `test` แบบอ่านอย่างเดียวภายหลัง: ref ตรงกับ Ops, `SUBSCRIPTION`/`BUSINESS` v2026.1/`ACTIVE`, source revision 2, local revision 4, quota 15/3/6, included modules `asset,crm`, Production=true; `trial_issued_at` ยังว่างตามข้อจำกัดทาง A. ส่งซ้ำเมื่อ 05:57 UTC: Ops audit `instance.sync.delivered` status `unchanged` source revision 2; ERP local revision ยัง 4, `source_local_revision=4` และ audit `platform.instance_entitlement.synced` มีเพียง 1 ครั้ง. ผู้ใช้แจ้งว่าทดสอบ suspension/recovery แล้ว: ERP audit แสดง source revision 3 `SUSPENDED` (06:07 UTC) และ revision 4 `ACTIVE` (06:08 UTC), Ops delivery ทั้งคู่ `applied`; ปัจจุบันทั้งสองระบบ `ACTIVE`, ERP local revision 6. ผล browser read/write เป็นคำยืนยันจากผู้ใช้ ยังไม่ได้ตรวจซ้ำโดยทีมพัฒนา. ผู้ใช้ทดลองเปลี่ยนเป็น License: Ops delivery revision 5 `applied` (06:12 UTC); ตรวจ ERP read-only พบ `LICENSE`/`ACTIVE` revision 5, ไม่มี plan/period/quota, modules ว่าง, Production=false; Ops ปัจจุบัน License เหมือนกัน. ผู้ใช้ยืนยันว่าเข้าหน้า ERP และคลิกใช้งานผ่าน browser ขณะ License ได้; ทีมพัฒนาไม่ได้รัน browser test และยังไม่มีหลักฐานทดสอบสร้างรายการเกิน quota จริง. ผู้ใช้ส่งกลับ `SUBSCRIPTION`/Business revision 6 แล้ว แต่ ERP หน้าโควตาแสดง **อ่านอย่างเดียว** (ยังไม่ผ่านการทดสอบ writable): ตรวจ Ops/ERP read-only พบ `starts_at=2026-09-28 13:36 UTC` ขณะที่ตรวจเวลา ERP `06:38 UTC` วันเดียวกัน จึงยังไม่เริ่มตามกฎ `hasActiveSubscription()`. หน้า Ops เปลี่ยนเป็นแสดง/รับ **เวลาไทย (Asia/Bangkok, UTC+7)** แต่ยังเก็บและส่ง UTC เดิม; ค่าเก่าที่เก็บเป็น `13:36 UTC` จะปรากฏบนฟอร์มเป็น `20:36 เวลาไทย` โดยไม่ย้ายข้อมูลอัตโนมัติ. ผู้ใช้แก้เวลาเริ่มใน Ops ผ่าน UI และส่ง revision 7 ได้ `applied`; ตรวจ ERP แบบอ่านอย่างเดียวพบ Business/ACTIVE, เริ่ม `2026-09-28 04:36 UTC` (`11:36 เวลาไทย`), local revision 9 และ `hasActiveSubscription()=true`; ผู้ใช้ยืนยันว่าหน้า ERP ใช้งานได้แล้ว. ไม่แก้ DB ตรงหรือส่งซ้ำแทนผู้ใช้. **ยังไม่ทดสอบ** rotation, reconciliation หรือ restore drill.
- ผู้ใช้เปลี่ยนปลายทาง local จาก `http://127.0.0.1:8000` เป็น `http://localhost:8000` แล้วส่งซ้ำ: ภาพหน้า Ops แสดง `unchanged` revision 7; อ่าน Ops audit พบ `instance.sync.target_changed` ตามด้วย `instance.sync.delivered` ไปปลายทางใหม่ (`unchanged`, revision 7). ERP ยังเป็น source revision 7/local revision 9. ยังไม่ได้ตรวจสถานะหน้าเว็บ **ก่อน** กดส่ง และยังไม่ได้ทดสอบการเปลี่ยน HTTPS domain จริง.
- ไม่บันทึก secret/signature ในหลักฐาน. ความสำเร็จของ local loopback ไม่แทนผล DNS/HTTPS/firewall สำหรับ deployment ภายนอก.

## Pairing ผ่านหน้าเว็บแบบแยกฐาน (รออนุมัติ migration ERP)

ERP เพิ่ม `/ops-pairing` สำหรับ Admin ที่เข้า HTTPS หรือ local loopback: ผูก ref ใหม่, สุ่ม key แสดงครั้งเดียว, เก็บเข้ารหัสใน DB ERP ของ Instance, เปิด/ปิด receiver ด้วยเหตุผลและ audit; Ops Admin กรอก endpoint/Key ID/secret จากหน้านี้ในหน้า Instance แล้วกด push แยกต่างหาก (production รับเฉพาะ HTTPS ใน allowlist). ฐานเดิมที่ใช้ไฟล์ key local ยังทำงานได้ แต่ไฟล์นั้นไม่ใช้กับ Instance ที่ผูกแบบ DB ใหม่. ผู้ใช้ยืนยัน backup/restore และอนุมัติแล้ว: รัน **เฉพาะ** ERP migration `2026_09_28_160000_create_instance_sync_keys_table.php` บน MySQL `test2` (batch 3 Ran) หลังตรวจ connection/Pending; schema มี key table ว่าง, ref ยังว่าง, receiver แบบ DB ยังไม่เปิดและไม่แตะ key file เดิม; ERP/Ops route cache refresh แล้ว. `test` ยังไม่รัน migration นี้ และผล backup/restore เป็นคำยืนยันผู้ใช้ ยังไม่ได้ตรวจหลักฐานอิสระ. ต่อไปสร้าง Ops Instance ใหม่ก่อนจับคู่จริง. ยังไม่ได้เพิ่ม UI หมุน/เลิกใช้ key ใหม่แบบ DB-backed: ห้ามถือว่าพร้อม production หรือพยายามใช้ key เดิมของ Instance อื่น.

## Gate A — migration/local push เสร็จแล้ว; งานที่ยังค้างก่อน production

- [ ] ระบุเจ้าของ UAT, Ops Admin และ ERP Admin, Product `MINTERP`, UUIDv7 **ใหม่** ของ Instance แยก, ฐาน/APP_KEY/private storage/โดเมนที่ไม่ใช่ของลูกค้าจริง. หาก clone มี production ref/secret ต้องแยกการเข้าถึงก่อน; ห้ามแก้ ref ด้วย SQL.
- [x] เจ้าของยืนยัน ERP=`test` และ Ops=`ops_portal` เป็นฐาน local ไม่มีข้อมูลลูกค้าจริง และอนุมัติ migration สองไฟล์ที่ระบุ.
- [x] ตรวจ connection/`php artisan migrate:status` ซ้ำก่อน apply และรันด้วย `--path` เฉพาะสองไฟล์; หลังรันสถานะ Ran และคอลัมน์ครบ (อย่าใช้ `migrate` แบบรวมทุก pending บน Instance ถัดไป).
- [ ] เจ้าของแจ้งว่าทำ manual backup แล้ว; ยังต้องตรวจหลักฐานว่า backup ฐาน **ทั้งสองระบบ** พร้อม private objects/APP_KEY/config ตามขอบเขต และทดสอบ restore ไปปลายทางแยก (ไม่ใช่เพียงมีไฟล์ backup). ไม่ส่ง backup/secret เข้า Git, URL, chat หรือ shell arguments.
- [ ] เลือก UAT แบบ local บนเครื่องเดียวกัน (`APP_ENV=local` ทั้ง Ops/ERP): ส่งไป `http://127.0.0.1:8000` หรือ `http://localhost:8000` ผ่าน loopback เท่านั้น ไม่ต้องมี HTTPS/allowlist; HTTP ไม่มี TLS ห้ามเปิด listener ให้เครือข่ายที่ไม่ไว้ใจ. หากทดสอบข้ามเครื่องหรือ production ต้องใช้โดเมน public IPv4 DNS, HTTPS certificate ตรงชื่อ host, port 443 และ ERP HTTPS route; เพิ่ม host ใน `OPS_ENTITLEMENT_ALLOWED_HOSTS`, จำกัด firewall egress และตรวจ DNS ทุกคำตอบว่าไม่ใช่ private/loopback/link-local. ตรวจนาฬิกาทั้งสองระบบ (HMAC ยอมรับ ±300 วินาที).
- [x] ผู้ใช้อนุมัติ migration สองไฟล์บน MySQL local หลังแจ้งว่าทำ backup ด้วยมือ. **ยังไม่ใช่การอนุมัติให้ bind ref, ตั้ง secret, เปิด receiver หรือ push**; ห้ามเปลี่ยน APP_ENV เพื่อเลี่ยง guard.

## Gate B — ข้อกำหนด UAT API ก่อนเชื่อมจริง

1. Migration บน local ข้างต้น **รันแล้ว**; คำสั่งที่ใช้ (เพื่อบันทึกเท่านั้น ไม่ต้องรันซ้ำ และต้องตรวจ connection/backup/ขออนุมัติใหม่สำหรับ Instance อื่น):

   ```sh
   # จาก new-erp — ตรวจ DB=test/Instance เป้าหมายอีกครั้งก่อนรัน
   php artisan migrate --path=database/migrations/2026_09_26_130000_add_sync_revision_to_instance_entitlements.php
   # จาก ops-portal — ตรวจ DB=ops_portal/Instance เป้าหมายอีกครั้งก่อนรัน
   php artisan migrate --path=database/migrations/2026_09_26_170000_create_instance_sync_credentials_table.php
   ```

2. ทีมงานออก secret 32 bytes สำหรับ Instance UAT ผ่านช่องทาง server-side ที่ป้องกันได้ (ไม่สร้างล่วงหน้าตอนนี้), ติดตั้งใน ERP deployment secret, ผูก UUIDv7 ด้วย audited `erp:entitlement:bind`; Ops enroll ผ่าน stdin โดยระบุ origin ที่อนุมัติ (local HTTP loopback หรือ HTTPS สาธารณะ). `instance_ref` ไม่ใช่ credential. เปิด receiver เฉพาะ ERP UAT หลังตรวจ route/transport/backup; ไม่เปิดบนลูกค้าจริง.
3. Admin สร้าง Trial/Subscription หรือ License ของ **Instance UAT** ใน Ops และผู้ดูแลสั่ง `ops:entitlement:push` แบบ manual. ตรวจ ERP ตอบรับ revision/status, audit ทั้งสองฝั่ง และหน้า Instance ว่าระบุปลายทาง/ผลส่งจริง; บันทึกเฉพาะหลักฐานที่ลบ secret/signature แล้ว. Save ใน Ops หรือเปลี่ยนโดเมนไม่ส่งอัตโนมัติ.
4. ให้ผู้ใช้ UAT: push ซ้ำเป็น no-op, revision เก่า/ต่างเนื้อหาถูกปฏิเสธ, product/ref/key/เวลาไม่ตรงถูกปฏิเสธ, DNS private/redirect/TLS ผิดไม่ส่ง, Ops ล่ม/ส่งไม่ถึงแล้วยังบังคับ snapshot ใน ERP. ตรวจ Trial 30 วัน/ออกซ้ำ/หมดอายุ, Production opt-in, Trial→paid, quota/โมดูล, License bypass, SUSPENDED ของทั้งสอง mode และการกลับ ACTIVE/logout.
5. ทดสอบเปลี่ยน HTTPS domain ผ่านหน้า Instance: host ใหม่ต้องอยู่ใน allowlist และชี้ **ERP ตัวเดิม**; สถานะเปลี่ยนเป็นยังไม่ยืนยันจน push ไปโดเมนใหม่แล้วได้คำตอบ; secret/ref/revision ไม่เปลี่ยนเพียงเพราะแก้โดเมน. ถ้าเป็น clone/ติดตั้งใหม่ต้องลงทะเบียน Instance และ secret ใหม่ ไม่ย้าย production ref. ทดสอบ key rotation/old-key fallback/เลิกใช้ key เก่า **หลัง** ยืนยันที่โดเมนปัจจุบัน, และ local emergency override→409→audited reconcile→Ops bump→push.
6. ตรวจ restore drill **DB + private storage** บนปลายทางแยก โดยปิด outbound ของ clone; บันทึกผล MySQL fresh-install/down-migration, firewall egress/TLS, API/browser และ VPS load. คืน receiver/secret UAT ตามนโยบายหลังทดสอบ; production ต้องผ่านการรับรองต่างหาก.

### ทาง A — ขั้น local ที่ผู้ใช้ทำเอง (ส่งครั้งแรกแล้ว; ข้อความต่อไปเป็นขั้นตอนอ้างอิง)

**ทางหน้าเว็บ (แนะนำสำหรับ local UAT):** หลังตรวจ backup/snapshot ไปที่ ERP `http://127.0.0.1:8000/local-uat/ops-pairing` ด้วย ERP Admin, วาง `instance_ref` จากหน้า Ops และเหตุผล, กดสร้าง key/ผูก Instance. Secret เข้ารหัสในไฟล์ local ที่อยู่นอก web root (ขึ้นกับ APP_KEY) และแสดงครั้งเดียวโดยไม่ส่งลง log/session. คัดลอก Key ID และ Secret ไปที่ Ops `http://127.0.0.1:8001/admin/instances/1/entitlement` ด้วย Ops Admin, ตั้ง endpoint `http://127.0.0.1:8000` และกดผูกปลายทาง. ยังไม่มีการส่งสิทธิ์; เปิด `ERP_ENTITLEMENT_SYNC_ENABLED=true` เฉพาะ ERP local ใน deployment config และ reload ก่อนกดปุ่ม **ส่งสิทธิ์ไป ERP local** ใน Ops. ปุ่มจะแสดงผล revision/สถานะหลังส่ง; ตรวจ ERP/audit และลองส่งซ้ำ `unchanged`. เว็บทั้งสองฝั่งเปิดการจับคู่/ส่งผ่าน UI เฉพาะ local loopback/Admin; **ไม่เขียน `.env` หรือเปิด receiver เอง**, production ยังใช้ CLI/deployment secret ตามปกติ. หากมี key ERP จาก `.env` หรือ ref ผูกแล้ว ห้ามสร้างทับผ่าน UI.

**ทาง CLI สำรอง** (ใช้เมื่อทีมปฏิบัติการเลือก):

1. ตรวจ snapshot Business/Production/quota ข้างต้นและ backup อีกครั้ง. ตั้ง key ID กับ secret 32 bytes (64 ตัว hex) ชุดเดียวกันใน ERP local deployment secret (`ERP_ENTITLEMENT_SYNC_KEY_ID`, `ERP_ENTITLEMENT_SYNC_SECRET`) และไฟล์/secret store ที่ป้องกันสำหรับ Ops stdin; ห้ามส่ง secret ผ่าน chat, URL หรืออาร์กิวเมนต์. คง `ERP_ENTITLEMENT_SYNC_ENABLED=false` จนผูกสำเร็จ.
2. เลือก active ERP Admin และ active Ops Admin ที่ถูกต้อง, จากนั้นผู้ใช้รันตาม repo (แทนค่าตัวพิมพ์ใหญ่และ path ด้วยค่าจริงโดยไม่ใส่ secret ในคำสั่ง):

   ```sh
   # new-erp: ผูก ref ครั้งเดียว (เพิ่ม local revision และ audit)
   php artisan erp:entitlement:bind 01a0e58b-d861-70c2-ab02-7306f087ff86 --actor=ERP_ADMIN_ID --reason='Local UAT pairing reviewed'
   # ops-portal: อ่าน secret จาก protected stdin; ไม่มี secret ใน argv/history
   php artisan ops:entitlement:enroll 01a0e58b-d861-70c2-ab02-7306f087ff86 http://127.0.0.1:8000 KEY_ID --actor=OPS_ADMIN_ID < /path/to/protected-secret.hex
   ```

3. ผู้ใช้ตรวจว่า ERP ยังรัน local/loopback และ key/ref ถูกต้อง แล้วเปิด `ERP_ENTITLEMENT_SYNC_ENABLED=true` เฉพาะ ERP UAT, reload config/process. เมื่อพร้อมรับการเปลี่ยนสิทธิ์จึงรัน `php artisan ops:entitlement:push 01a0e58b-d861-70c2-ab02-7306f087ff86 --actor=OPS_ADMIN_ID` จาก Ops. เทียบ ERP `source_revision=2`, Business v2026.1, quota 15/3/6, CRM+Asset และ Production=true พร้อม audit/หน้า Ops; retry ควรเป็น `unchanged`. หากผลไม่ตรงให้หยุดและตรวจ ไม่แก้ DB ด้วย SQL. ปิด receiver หลังจบ UAT ตามแผน; การ bind/ref ไม่ยกเลิกอัตโนมัติ.

**ยังบล็อก production:** ไม่มีผล backup/restore drill, firewall/TLS และ UAT ข้ามสองระบบที่ผู้ใช้ลงนาม; อย่าอ้างว่า Unit/Contract tests เป็นผล UAT จริง. MintPOS/MintHRM ยังไม่มี receiver/adapters.
