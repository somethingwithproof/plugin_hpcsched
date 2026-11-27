<!-- Source: README.md; SHA256: 938114d41dbd516f9dfcd94619c4fc73ef56b4da1dd693010588a93cce250534 -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_hpcsched

إضافة لـ Cacti 1.2 (`hpcsched`، الإصدار `0.1.0-dev`) تقوم برسم مبيانات حالة مجدول المهام الدفعية للحوسبة عالية الأداء (HPC) بجانب الأجهزة التي يراقبها Cacti مسبقاً: عمق طابور الانتظار، المهام المعلقة مقابل المهام قيد التشغيل، أسباب تعليق المهام، حالات العقد، معدل استغلال الأقسام، واستخدام الحصة العادلة (fair-share) على مستوى الحساب. يُعدّ Slurm الخلفية المنفذة. يطلق Thold تنبيهات على مصادر البيانات الناتجة، كما يقود مزامن Maint الاختياري نوافذ Cacti Maint انطلاقاً من عقد الحوسبة المفرغة (drained) والمتعطلة والمحجوزة للصيانة في Slurm.

> **الحالة: قيد التطوير، لا يوجد إصدار نهائي بعد.** ينفذ هذا الفرع أساس الإضافة، ومبيانات العناقيد والأقسام، وعائلات أسباب التعليق، ومطابقة عقد حوسبة Slurm مع أجهزة Cacti، والمزامنة الاختيارية لنوافذ Cacti Maint، وملخصات الحصة العادلة على مستوى الحساب، والتنفيذ والنسخ المتماثل على جامعي البيانات البعيدين، وواجهة سطر أوامر للإعدادات كشيفرة (CasC) والعمليات، وخطافات منظومة Cacti (CasC وDoctor وMCP / Abilities API v2). تغطي مجموعة الاختبارات أوامر Slurm الاصطناعية واستجابات `slurmrestd`، كما يختبر CI عنقود Slurm حياً من ست عقد داخل حاويات. لم يعمل القارئ بعد على عنقود إنتاجي، ولا يوجد بعد اختبار إعادة تشغيل شامل لـ Thold في CI.

هذا مشروع مستقل ولا يرتبط بمشروع Cacti.

## ما تم تنفيذه

- **دورة الحياة والمخطط (v3)**: التثبيت، الترقية المتكررة الآمنة (idempotent)، إلغاء التثبيت (مع الاحتفاظ بجداول الإضافة افتراضياً)، وحد أدنى لإصدار PHP 8.2 مع إبقاء نقاط الدخول قابلة للتحليل على PHP 8.0. يضيف المخطط v3 جدولي `plugin_hpcsched_node_map` و`plugin_hpcsched_maint_link` بجانب جداول العناقيد والتشغيلات والأقسام الفرعية وأعداد المهام وأعداد العقد والعقد والأقسام والحجوزات والحصص.
- **جمع Slurm للقراءة فقط على المجمّعين الرئيسي والبعيدين**: يشغل `poller_bottom` الأمر `poller_hpcsched.php --cluster=N` في الخلفية على كل مجمّع (`poller_id`) للعناقيد المفعّلة المسندة إليه. يجمع كل تشغيل لقطة مخبّأة واحدة لكل عنقود عبر أوامر العميل (`squeue` و`scontrol` و`sshare` مع `--json`) أو عبر `slurmrestd` عبر HTTPS (يُسمح بـ HTTP على عنوان loopback مع تحذير صحة). يعلن `INFO` عن `remote_collect:1, remote_poller:1`، وينسخ `replicate_out` تعريفات `plugin_hpcsched_cluster` غير السرية إلى الجامعين البعيدين (`poller_id >= 2`)؛ ولا تتم قراءة محتويات ملفات الرموز أو نسخها أبداً.
- **قوالب Cacti ومبيانات العناقيد**: يتضمن قوالب الأجهزة والبيانات والمبيانات (`templates/hpcsched-templates.xml`) واستعلام بيانات خادم السكربتات للأقسام (`scripts/ss_hpcsched_cluster.php` و`scripts/ss_hpcsched_partitions.php`). وعند تأكيد المدير، ينشئ أو يربط جهاز Cacti واحداً لكل عنقود مع مبيانات للمهام حسب الحالة، والمهام المعلقة حسب عائلة السبب، والعقد حسب الحالة، ووحدات المعالجة المركزية (CPU)، ومعدل الاستغلال (على مستوى العنقود ولكل قسم)، وعمر اللقطة.
- **مطابقة عقد Slurm مع الأجهزة ومزامنة Cacti Maint**: يربط المطابق ذو الزمن الخطي من أربع طبقات (`NodeMatcher`) عقد حوسبة Slurm بسجلات `host` الموجودة في Cacti (اسم المضيف الدقيق، العنوان الدقيق، الوصف/الاسم المستعار الدقيق، أو أول تسمية DNS للأسماء غير المستندة إلى IP) ويسمي التطابقات المتعددة أو المطالبات المكررة للجهاز `ambiguous` بدلاً من التخمين. يوسّع `HostList::expand()` قوائم مضيفي حجوزات Slurm (حتى 10,000 عقدة). ينشئ `MaintSynchronizer` ويجدد ويزيل جداول Cacti Maint لمرة واحدة المملوكة للإضافة والمتتبّعة في `plugin_hpcsched_maint_link` للعقد المرتبطة في حالات `DRAIN` أو `DRAINING` أو `DOWN` أو حجوزات `MAINT`/`MAINTENANCE` النشطة دون تعديل جداول Maint التي أنشأها المدراء.
- **ملخصات الحصة العادلة على مستوى الحساب**: عند تفعيل `collect_shares`، يجمع صفوف ارتباط حسابات Slurm في `plugin_hpcsched_share` (مع إسقاط صفوف المستخدمين وفق [ADR 0010](../../decisions/0010-bound-series-cardinality.md)). يحسب `ShareSummary` و`ShareRepository` أعداد الحسابات والأقسام، وإجماليات الاستخدام الخام والفعلي، والحد الأدنى والأقصى والمتوسط لعوامل الحصة العادلة، وأعلى الحسابات؛ وتُعرض أعداد الحسابات في `hpcsched-get-queue-summary`.
- **واجهة سطر أوامر CasC والعمليات (`cli/hpcsched.php`)**: تصدير إعدادات YAML/XML، التحقق من الصحة، تخطيط الفروقات بالتشغيل التجريبي، التطبيق المشروط بالتأكيد، مخرجات الصحة المهيكلة، ومزامنة Maint الاختيارية (`sync-maint --dry-run` / `--apply`).
- **خطافات المنظومة (`setup.php`)**: `casc_register_providers` و`doctor_register_checks` و`abilities_register`.

## أدوار الوصول

| النطاق (Realm) | مستوى الوصول |
| --- | --- |
| HPC Scheduler View | نظرة عامة للقراءة فقط على العناقيد وأسباب التعليق (`hpcsched.php`). |
| HPC Scheduler Operator | صلاحية View بالإضافة إلى صفحة العمليات (`hpcsched_operator.php`) وتصديقات الفرز الشخصية. |
| HPC Scheduler Admin | صلاحية Operator بالإضافة إلى إدارة العناقيد (`hpcsched_clusters.php`) وصفحة الصحة (`hpcsched_health.php`). |

بعد نسخ ملفات الترقية، افتح صفحة Plugins بصفتك مديراً لتسجيل النطاقات والخطافات الجديدة. عيّن نطاق Operator صراحةً ضمن **Configuration > Users** أو مجموعات المستخدمين. تحتفظ الترقيات بمعرّفات ومنح نطاقي View وAdmin الحالية. يرث وصول Admin وصول Operator، ويرث وصول Operator وصول View عند كل طلب؛ ولا يضيف ذلك منحاً مخزّنة للمستخدمين أو المجموعات. تتطلب إعدادات الإضافة أيضاً صلاحية HPC Scheduler Admin بالإضافة إلى صلاحية Settings في Cacti.

تقيّد صلاحيات أجهزة Cacti الصفوف والإجراءات المتاحة لكل مستخدم. لا يُعرض العنقود المرتبط بجهاز Cacti ولا يُصدّر ولا يُعدّل إلا للمستخدمين الذين يسمح لهم Cacti بهذا الجهاز؛ كما يتطلب ربط جهاز صلاحيةً على الجهاز الهدف. يُدرج العنقود غير المرتبط بجهاز في الصفحات الإدارية للمستخدمين الذين لديهم نطاق الإضافة، وليس في عروض المشغّل التي تدرج العناقيد المرتبطة بأجهزة فقط. أما العنقود الذي حُذف جهازه المرتبط أثناء تعطيل الإضافة فلا يُعرض إلا للمستخدمين ذوي وصول HPC Scheduler Admin. تدوم التصديقات في جلسة المستخدم الحالية فقط، ولمدة ساعة واحدة كحد أقصى وفقط ما دامت الملاحظة نفسها حديثة. وتُبطلها الملاحظات الجديدة أو المتغيرة. وهي لا تصنّف أبداً قياساً فاشلاً كسليم ولا تكتم التنبيهات.

## ملاحظات المشغّل

### الأمان، أقل امتياز، وملفات الرموز

تقرأ الإضافة فقط. خصّص لها مستخدم Slurm مكرساً من دون دور مدير أو مشغّل (`AdminLevel=None` في `sacctmgr`). مع وجود `PrivateData=jobs` (أو `nodes` أو `reservations` أو `usage`) في `slurm.conf`، لا يرى هذا المستخدم إلا سجلاته الخاصة، فتكون الأعداد ناقصة ولا تستطيع الإضافة اكتشاف ذلك. يتطلب رفع هذا القيد عن الحساب مستوى مشغّل أو مدير، وهو ما يسمح أيضاً بالتغييرات؛ ويبقى هذا الاختيار تقديرياً حسب الموقع.

بالنسبة لأوامر العميل، يحتاج مستخدم مجمّع Cacti إلى ثنائيات عميل Slurm (`squeue` و`scontrol` واختيارياً `sshare`) وإلى ملف `slurm.conf` يصل إلى المتحكم؛ ومسارات الثنائيات هي إعدادات مطلقة ضمن **Configuration > Settings > HPC Scheduler**.

بالنسبة لـ `slurmrestd`، يجب أن يكون عنوان URL بصيغة `https://`، أو `http://` على عنوان loopback فقط. يتم التحقق من شهادات TLS ولا يتم اتباع عمليات إعادة التوجيه. تقيّد قائمة مضيفي `slurmrestd` الاختيارية ضمن **Configuration > Settings > HPC Scheduler** المضيفين والمنافذ التي يمكن للعنقود تحديدها.

يسمّي عنقود `slurmrestd` ملف رمز؛ ولا يُخزّن الرمز نفسه أبداً في قاعدة البيانات، ولا يُعرض في صفحة، ولا يُصدّر عبر CasC، ولا يُكتب في السجل. يجب أن يقع الملف داخل أحد مجلدات ملفات الرموز المكونة ضمن **Configuration > Settings > HPC Scheduler**، وأن يتم الوصول إليه بدون رابط رمزي، وأن يكون ملفاً عادياً يملكه مستخدم المجمّع ولا يملك صلاحية الكتابة عليه سواه، ويحتوي على سطر واحد: الرمز أو `SLURM_JWT=<token>` كما يطبعه `scontrol token`. بدون تعيين أي مجلدات رموز، لا يمكن لأي عنقود استخدام `slurmrestd`. أبقِ كل مجلد رموز قابلاً للكتابة فقط من قِبل root ومستخدم المجمّع. يظل الملف القابل للقراءة من المجموعة أو المستخدمين الآخرين مستعملاً، لكن صفحة الصحة تصدر تحذيراً؛ كما تحذّر عندما تنتهي صلاحية الرمز ضمن نافذة التحذير المكونة.

لتدوير الرمز ذرياً (atomically):

```sh
dir=/etc/cacti/hpcsched
tmp=$(mktemp "$dir/.token.XXXXXX")
scontrol token username=cactiread lifespan=2592000 > "$tmp"
chown cacti:cacti "$tmp"
chmod 0600 "$tmp"
mv -f "$tmp" "$dir/cluster1.token"
```

تقرأ عملية الجمع التالية الملف الجديد. يتطلب توليد الرموز عبر `scontrol token` مدير Slurm؛ شغّله حيث يُسمح بذلك وانسخ الملف إلى المجمّع.

### جامعو البيانات البعيدون

يعلن `INFO` عن `remote_collect:1, remote_poller:1`. يشغّل `hpcsched_poller_bottom()` البرنامج `poller_hpcsched.php` على كل مجمّع للعناقيد المفعّلة المسندة إلى ذلك `poller_id`، وينسخ `hpcsched_replicate_out()` صفوف `plugin_hpcsched_cluster` غير السرية إلى المجمّعين البعيدين (`poller_id >= 2`). يجب أن توجد ملفات الرموز وثنائيات عميل Slurm محلياً على المجمّع البعيد المسند؛ ولا يتم نسخ محتويات الرموز أبداً.

### حدود الذاكرة

يفك كل تشغيل جمع ترميز إجابة مجدول واحدة في كل مرة، وهو ما يستهلك نحو 12 ضعف حجم الإجابة في ذاكرة PHP (تم قياسه بنحو 10 أضعاف لسجلات المهام ذات بنية Slurm، بالإضافة إلى النص نفسه). يرفع القارئ حد ذاكرة PHP لديه إلى إعداد Collection Memory (1024 MiB افتراضياً) ويسمح بإجابات تصل إلى `(Collection Memory - 64) / 12` MiB (80 MiB افتراضياً)، حتى عندما يكون Maximum Answer Size مضبوطاً على قيمة أعلى؛ وتحذّر صفحة الصحة عندما يتجاوز Maximum Answer Size هذا السقف الملاءَم. الإجابة الأكبر تفشل قسمها لكونها كبيرة جداً، والتشغيل الذي يظل يستنفد الذاكرة يسجّل فشل ذاكرة ويحرّر عقد الإيجار وفتحة العملية عبر معالج إغلاق. قد تحتاج العناقيد الكبيرة إلى رفع كلا الإعدادين؛ ولم يتم قياس 100,000 سجل مهمة حقيقي.

### القوالب، أجهزة العناقيد، وحساب المبيانات

تستورد عمليتا التثبيت والترقية قوالب الأجهزة والبيانات والمبيانات لـ HPC Scheduler وتنسخان استعلام بيانات الأقسام إلى مجلد `resource/script_server/` في Cacti. إذا لم يكن هذا المجلد قابلاً للكتابة، أو وُجد هناك ملف مختلف بنفس الاسم، أو كان الهدف رابطاً رمزياً، يتوقف الاستيراد ويترك الملف كما هو ويُبلغ عن الحالة في صفحة الصحة حيث يعيد **Install Templates** المحاولة. وتزيل عملية إلغاء التثبيت ملف الاستعلام فقط إذا كانت الإضافة قد كتبته ولم يتغير، وتحتفظ بالقوالب والأجهزة والمبيانات.

يعرض **HPC Clusters > Device** معاينة للمبيانات ومصادر البيانات وملفات RRD واستدعاءات خادم السكربتات لكل استطلاع قبل إنشاء أي شيء. ينشئ **Create Device** جهازاً باسم مضيف في النطاق المحجوز `.invalid` ومن دون فحص توفر؛ ويربط **Map Device** المبيانات بجهاز موجود من دون تغيير إعداداته. يمتلك كل مبيان للعنقود وكل مبيان للقسم مصدر بيانات خاصاً به: ستة للعنقود وخمسة لكل قسم. يُعاد فهرسة الأقسام المضافة لاحقاً تلقائياً عندما يكتشف القارئ تغييراً؛ وتتوقف الأقسام المحذوفة عن الاستطلاع وتحتفظ بمبياناتها. يؤدي حذف جهاز إلى فك ارتباط العنقود (ومسح أي صفوف مطابقة في `plugin_hpcsched_node_map`) مع الإبقاء على سجل العنقود.

تستبعد المهام المعلقة (Pending) المهام المحتجزة (held) التي لها سلسلتها الخاصة. وتحسب مصفوفة المهام المعلقة مهامها المتبقية. وتُحتسب المهمة أو العقدة الموجودة في عدة أقسام مرة واحدة في مبيانات العنقود ومرة واحدة في كل قسم، لذا لا يلزم أن يساوي مجموع الأقسام إجمالي العنقود. وتُجمع أسباب التعليق في إحدى عشرة عائلة ([ADR 0011](../../decisions/0011-pending-reason-families-and-counting.md))؛ وتعرض صفحة أسباب التعليق الأسباب الخام بما في ذلك تلك المصنّفة في `Other`. يشمل مقام معدل الاستغلال العقد المفرغة والمتعطلة وسائر العقد غير المتاحة. وتُبلّغ البيانات القديمة أو الفاشلة كـ `U`، لا صفراً أبداً؛ ويبقى `age` رقمياً.

للتنبيه، اضبط Thold على أي من مصادر بيانات العنقود أو القسم بمدد لا تقل عن فاصل جمع واحد، واضبط تنبيهاً على `age` لاكتشاف توقف القارئ.

## واجهة سطر الأوامر وخطافات المنظومة

### نقطة دخول القارئ

يشغّل `hpcsched_poller_bottom()` البرنامج `poller_hpcsched.php` تلقائياً؛ ويمكن أيضاً تشغيله يدوياً على المجمّع المسند للعنقود:

```sh
php /path/to/cacti/plugins/hpcsched/poller_hpcsched.php --cluster=N [--force] [--debug]
```

يعمل `--force` حتى أثناء تعطيل الإضافة، للتشخيص عبر سطر الأوامر.

### واجهة سطر الأوامر لـ CasC والصحة ومزامنة Maint

```sh
php /path/to/cacti/plugins/hpcsched/cli/hpcsched.php export [--format=yaml|xml] [--file=PATH]
php /path/to/cacti/plugins/hpcsched/cli/hpcsched.php validate --file=PATH [--format=yaml|xml]
php /path/to/cacti/plugins/hpcsched/cli/hpcsched.php plan --file=PATH [--format=yaml|xml]
php /path/to/cacti/plugins/hpcsched/cli/hpcsched.php apply --file=PATH --confirm=PLAN_HASH [--format=yaml|xml]
php /path/to/cacti/plugins/hpcsched/cli/hpcsched.php health [--format=text|json]
php /path/to/cacti/plugins/hpcsched/cli/hpcsched.php sync-maint [--dry-run | --apply] [--cluster=ID] [--format=text|json]
```

- يكتب `export` الملفات الجديدة بالوضع `0600` (أو إلى stdout) ويستبدل مسارات `token_file` المكونة بـ `[REDACTED]`. يرفض `plan` و`apply` الحقول المضمنة `jwt` و`secret` و`password`، ويحافظان على مسارات `token_file` المحلية الموجودة عند تمرير `[REDACTED]`. يتطلب `apply` قيمة `--confirm=PLAN_HASH` المطابقة تماماً لما طبعه `plan`.
- يقيّم `health` حداثة المخطط وحداثة العناقيد ونتائج التشغيل وتوفر Maint والتحذيرات من دون الاتصال بالمجدولات أو قراءة ملفات الرموز.
- يستخدم `sync-maint` الخيار `--dry-run` افتراضياً ويعاين أو يطبّق جداول Cacti Maint لعقد Slurm المرتبطة في حالات `DRAIN` أو `DRAINING` أو `DOWN` أو حجوزات `MAINT`/`MAINTENANCE` النشطة (الأفق الافتراضي للتفريغ/التعطل 3600 ثانية، وهامش التجديد 600 ثانية). ويرفض `--apply` إذا غاب جدولا `plugin_maint_schedules` و`plugin_maint_hosts`، ولا يحدّث أو يزيل إلا الجداول المسجلة في `plugin_hpcsched_maint_link`.

### خطافات المنظومة

عند توفر إضافات Cacti أو تكاملات المضيف المقابلة، يسجّل `setup.php`:

- `casc_register_providers`: يصدّر إعدادات `hpcsched` (`yaml` و`xml`، المخطط v1) ويتحقق منها ويخططها ويطبقها مع التأكيد ومع تنقيح الأسرار.
- `doctor_register_checks`: يوفّر أربعة فحوصات تشخيصية للقراءة فقط (`hpcsched.schema` و`hpcsched.cluster_freshness` و`hpcsched.token_health` و`hpcsched.maint_sync`).
- `abilities_register`: يسجّل ثلاث قدرات للقراءة فقط غير تدميرية ومتكررة الأثر ضمن الفئة `hpcsched`، مقيّدة بنطاق HPC Scheduler View (وصلاحيات الأجهزة لملخصات العناقيد): `hpcsched-list-clusters` و`hpcsched-get-queue-summary` و`hpcsched-get-health`.

## لماذا

يرسم المشغّلون الذين يديرون Cacti عقد الحوسبة لديهم بالفعل. وتغيب رؤية المجدول عن تلك المبيانات، مما يترك فجوتين:

- **عدم معرفة "لماذا لا يبدأ العمل".** يسجّل Slurm سبباً لكل مهمة معلقة. ووجود طابور ممتلئ بجانب مبيانات لعقد خاملة لا يوضح ما إذا كان السبب هو الأولوية أو حدود الموارد أو الاحتجاز أو التبعيات أو تفريغ العقد.
- **عدم وجود ربط بين حالة المجدول والتنبيهات.** يطلق Thold تنبيهات على جهاز قام المدير بتفريغه بالفعل، وينشئ المدراء جداول Maint يدوياً.

تجمع الإضافة لقطة مخبّأة واحدة لكل عنقود، وتنشرها كمصادر بيانات ومبيانات اعتيادية في Cacti، وتربط عقد المجدول بأجهزة Cacti الموجودة، وتزامن نوافذ Maint منتهية الصلاحية مملوكة للإضافة للعقد المفرغة والمتعطلة والمحجوزة للصيانة. وهي تعيد استخدام صلاحيات Cacti وجامعي البيانات البعيدين وThold وMaint، وتظل للقراءة فقط تماماً تجاه المجدول.

## النطاق والحدود

خارج النطاق: أي مسار كتابة إلى المجدول (الإرسال، الإلغاء، الاحتجاز، التفريغ، الاستئناف)، محرك تنبيهات مملوك للإضافة، محرك تقارير أو فوترة، وسلاسل لكل مستخدم أو لكل مهمة. الواجهات الخلفية PBS Pro وOpenPBS وLSF للبحث فقط.

## المتطلبات والتوافق

| المكوّن | الهدف |
|---|---|
| Cacti | 1.2.31 أو أحدث (`compat = 1.2.31`)، من دون تعديلات على النواة |
| PHP | 8.2 أو أحدث؛ تبقى نقاط الدخول قابلة للتحليل على 8.0 وترفض التفعيل على 8.1 حتى تتلقى بيئة التشغيل غير المدعومة رفضاً بدلاً من واجهة Cacti معطوبة |
| Slurm | 25.05 و25.11 و26.05 ([ADR 0013](../../decisions/0013-three-latest-slurm-releases.md))؛ يُختبر 26.05.5 في CI عند كل تغيير مقابل عنقود حاويات من ست عقد، و25.11.8 على `main` وأسبوعياً وعند الطلب، و25.05 مقابل بيانات اختبار ثابتة فقط. تستخدم أوامر العميل افتراضياً محلل البيانات `v0.0.43` (مع إتاحة اختيار `v0.0.44` و`v0.0.45`)؛ وتكتشف عناقيد `slurmrestd` أحدث محلل يقدمه الخادم |
| Thold وMaint | لا يتطلب تغييرات في Thold؛ يتكامل `sync-maint` مع جدولي `plugin_maint_schedules` و`plugin_maint_hosts` في Cacti Maint باستخدام جدول ربط مملوك للإضافة (`plugin_hpcsched_maint_link`) |

## التطوير والاختبار

يختبر مسار عمل CI (`.github/workflows/plugin-ci-workflow.yml`) إصدارات PHP 8.2 إلى 8.5 مقابل Cacti `release/1.2.31` و`1.2.x`، وفحص نحو نقاط الدخول على PHP 8.0، والرفض على PHP 8.1، وPHPStan، والتحقق من gettext/الترجمات، وترخيص REUSE، وفحص مسار العمل، وتدفق أجهزة العناقيد وفحوصات انحراف تصدير القوالب، وعنقود Slurm حياً من ست عقد (`slurm-live`).

باستخدام بيئة اختبار Pest المشتركة والمقفلة الخاصة بإضافات Cacti (`CACTI_PEST_BIN`) وقاعدة بيانات Cacti مؤقتة:

```sh
# Unit tests
"$CACTI_PEST_BIN" --configuration=phpunit.xml

# Lifecycle integration tests (disposable database only)
CACTI_PATH=/path/to/cacti HPCSCHED_TEST_DATABASE=disposable "$CACTI_PEST_BIN" --configuration=phpunit-integration.xml

# Static analysis (against Cacti 1.2.31)
CACTI_PATH=/path/to/cacti phpstan analyse -c phpstan.neon --no-progress

# Translation template, catalogs, and localized README checks
sh locales/build_gettext.sh --check
```

## التصميم

- [docs/PLAN.md](../../PLAN.md) هو خطة الهندسة المعمارية والتصميم: الحقائق المرجعية مع مصادرها، ربط العقد، نموذج الحالة، حدود الأمان، حسابات الأداء، الاختبار، والمخاطر.
- [docs/decisions/](../../decisions/) يضم سجلات قرارات الهندسة المعمارية (ADR).
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) يربط كل معرّف متطلب ببطاقة العمل الخاصة به.

```mermaid
flowchart LR
    S[متحكم Slurm] -->|استدعاءات للقراءة فقط، مجموعة واحدة لكل دورة| R[poller_hpcsched.php على المجمّع المسند]
    R --> SNAP[(جداول اللقطات)]
    SNAP --> SS[مدخلات خادم السكربتات]
    SS -->|poller_output| T[Thold]
    SS --> G[مبيانات وصلاحيات Cacti]
    SNAP --> MAP[NodeMatcher وMaintSynchronizer]
    MAP -->|جداول مملوكة منتهية الصلاحية| M[Cacti Maint]
    SNAP --> H[HealthEvaluator وDoctor وقدرات MCP]
```

## كيفية تتبع العمل

- **المعالم (Milestones)** تتبع معالم الخطة. لكل معلم بطاقة ملحمية (epic) تسرد بطاقاته الفرعية وأدلة الإغلاق.
- **البطاقات (Issues)** مصنفة حسب التسمية: `type: requirement` و`type: research` و`type: decision` و`type: epic`. تحدد بطاقات المتطلبات متطلبات مرقمة وقابلة للاختبار (`REQ-AREA-NNN`) ومعايير القبول.
- **التسميات (Labels)** تحمل أيضاً `area:` و`phase:` و`priority:` و`status:`. راجع [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- تعرض [لوحة المشروع](https://github.com/users/somethingwithproof/projects/10) جميع البطاقات عبر المعالم.

## المساهمة

اقرأ [CONTRIBUTING.md](../../../CONTRIBUTING.md). تحتاج الإيداعات إلى توقيع DCO (`git commit -s`). تجري مناقشة التصميم في البطاقات؛ وتحمل القرارات المفتوحة التسمية `type: decision`.

## الأمان

أبلغ عن الثغرات الأمنية بشكل خاص؛ راجع [SECURITY.md](../../../SECURITY.md).

## الترخيص

رخصة GNU General Public License v2.0، نفس رخصة Cacti وإضافاته. راجع [LICENSE](../../../LICENSE).
