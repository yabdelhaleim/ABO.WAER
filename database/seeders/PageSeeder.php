<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    /**
     * Seed the five storefront pages.
     *
     * The pages are rendered from this table by
     * App\Http\Controllers\Store\PageController — the seeder is what
     * puts the initial copy there. The copy is plain semantic HTML
     * because that is what the admin editor produces (a contenteditable
     * body) and what `pages.content` / `pages.content_ar` store.
     *
     * The seeder never overwrites an admin's work: the row is created
     * if it is missing, and the copy is only written when the page has
     * no content yet. Re-running it on an edited database is a no-op.
     */
    public function run(): void
    {
        $pages = [
            [
                'slug' => 'about',
                'title' => 'About Us',
                'title_ar' => 'من نحن',
                'content' => '<h2>Not made to blend in.</h2>'
                    .'<p>ABO started in 2024 with a simple idea: premium menswear that fits right, holds up, and looks like nobody else on the street. Everything we drop is designed in Cairo and built to outlast the season it was made for.</p>'
                    .'<h2>What we stand for</h2>'
                    .'<ul>'
                    .'<li><strong>Premium fabric.</strong> Heavyweight cotton, brushed fleece, real leather. Nothing thin, nothing shiny.</li>'
                    .'<li><strong>Modern fit.</strong> Cut for movement, never skin-tight, never sloppy.</li>'
                    .'<li><strong>Better than yesterday.</strong> Every drop is a refinement of the last.</li>'
                    .'</ul>'
                    .'<h2>Our story</h2>'
                    .'<ol>'
                    .'<li><strong>2024</strong> — The first drop. Eight pieces, one weekend, sold out in 36 hours.</li>'
                    .'<li><strong>2025</strong> — Growing the crew. A real warehouse and a small studio in Nasr City.</li>'
                    .'<li><strong>2026</strong> — Drop 02. A new season, a new fit block, and a wider range.</li>'
                    .'</ol>'
                    .'<p>If something does not fit right, <a href="/returns">send it back</a> or <a href="/exchange">swap it</a> — no friction, no fine print.</p>',
                'content_ar' => '<h2>نُصمّم لن لا نُنسى بين الآخرين.</h2>'
                    .'<p>بدأت ABO في عام 2024 بفكرة بسيطة: ملابس رجالية فاخرة-fit بشكل صحيح، تدوم، ولا تشبه أحداً في الشارع. كل تصميم يُصنع في القاهرة ويصمد أكثر من الموسم الذي صنع له.</p>'
                    .'<h2>ما نؤمن به</h2>'
                    .'<ul>'
                    .'<li><strong>خامة فاخرة.</strong> قطن ثقيل، وبر فرو، وجلد طبيعي — لا شيء رقيق أو هش.</li>'
                    .'<li><strong>قصّة عصرية.</strong> مصممة للحركة، لا ضيقة ولا فضفاضة.</li>'
                    .'<li><strong>أفضل من الأمس.</strong> كل دروب هو تطوير لما قبله.</li>'
                    .'</ul>'
                    .'<h2>قصتنا</h2>'
                    .'<ol>'
                    .'<li><strong>2024</strong> — الدروب الأول. ثماني قطع، عطلة أسبوع، نفدت خلال 36 ساعة.</li>'
                    .'<li><strong>2025</strong> — نمو الفريق. مستودع حقيقي واستوديو صغير في مدينة نصر.</li>'
                    .'<li><strong>2026</strong> — الدروب الثاني. موسم جديد وقصّة جديدة ومجموعة أوسع.</li>'
                    .'</ol>'
                    .'<p>إذا لم يناسبك المقاس، <a href="/returns">أرجعه</a> أو <a href="/exchange">استبدله</a> — بلا تعقيد وبلا شروط مخفية.</p>',
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact Us',
                'title_ar' => 'تواصل معنا',
                'content' => '<h2>Get in touch</h2>'
                    .'<p>Questions about an order, a size or a drop? We reply within 24 hours.</p>'
                    .'<h2>How to reach us</h2>'
                    .'<ul>'
                    .'<li><strong>WhatsApp</strong> — the fastest way to reach us, usually under an hour.</li>'
                    .'<li><strong>Phone</strong> — Saturday to Thursday, 10 AM to 10 PM.</li>'
                    .'<li><strong>Email</strong> — for order issues, wholesale and press.</li>'
                    .'</ul>'
                    .'<h2>Working hours</h2>'
                    .'<ul>'
                    .'<li>Saturday – Thursday: 10 AM – 10 PM</li>'
                    .'<li>Friday: 2 PM – 10 PM</li>'
                    .'</ul>'
                    .'<h2>Studio</h2>'
                    .'<p>Nasr City, Cairo, Egypt. Visits by appointment only.</p>'
                    .'<h2>What to include</h2>'
                    .'<ol>'
                    .'<li>Your order number (if you have one).</li>'
                    .'<li>The email you used at checkout.</li>'
                    .'<li>A short description of the issue.</li>'
                    .'</ol>',
                'content_ar' => '<h2>تواصل معنا</h2>'
                    .'<p>أسئلة عن طلب أو مقاس أو دروب؟ نرد خلال 24 ساعة.</p>'
                    .'<h2>كيف تصل إلينا</h2>'
                    .'<ul>'
                    .'<li><strong>واتساب</strong> — أسرع طريقة، عادة خلال ساعة.</li>'
                    .'<li><strong>الهاتف</strong> — من السبت إلى الخميس، 10 صباحاً حتى 10 مساءً.</li>'
                    .'<li><strong>البريد الإلكتروني</strong> — لمشكلات الطلبات والجملة والصحافة.</li>'
                    .'</ul>'
                    .'<h2>مواعيد العمل</h2>'
                    .'<ul>'
                    .'<li>السبت – الخميس: 10 صباحاً – 10 مساءً</li>'
                    .'<li>الجمعة: 2 ظهراً – 10 مساءً</li>'
                    .'</ul>'
                    .'<h2>الاستوديو</h2>'
                    .'<p>مدينة نصر، القاهرة، مصر. الزيارة بموعد مسبق فقط.</p>'
                    .'<h2>ما الذي يجب إرفاقه</h2>'
                    .'<ol>'
                    .'<li>رقم الطلب (إن وُجد).</li>'
                    .'<li>البريد الإلكتروني الذي استخدمته عند الشراء.</li>'
                    .'<li>وصف موجز للمشكلة.</li>'
                    .'</ol>',
            ],
            [
                'slug' => 'shipping',
                'title' => 'Shipping Policy',
                'title_ar' => 'سياسة الشحن',
                'content' => '<h2>Processing time</h2>'
                    .'<p>Orders placed before <strong>2 PM</strong> ship the same day. Orders placed after 2 PM ship the next business day.</p>'
                    .'<p>You will receive an <strong>SMS and email</strong> with a tracking link as soon as your order leaves our warehouse.</p>'
                    .'<h2>Delivery times and fees</h2>'
                    .'<table>'
                    .'<thead><tr><th>Area</th><th>Time</th><th>Fee</th></tr></thead>'
                    .'<tbody>'
                    .'<tr><td>Cairo and Giza</td><td>1–2 business days</td><td>60 EGP</td></tr>'
                    .'<tr><td>Alexandria and Delta</td><td>2–3 business days</td><td>75 EGP</td></tr>'
                    .'<tr><td>Upper Egypt and Canal</td><td>3–5 business days</td><td>90 EGP</td></tr>'
                    .'<tr><td>Remote areas</td><td>5–7 business days</td><td>110 EGP</td></tr>'
                    .'</tbody></table>'
                    .'<h2>Good to know</h2>'
                    .'<p>The courier will attempt delivery <strong>twice</strong>. Keep your phone on — the driver will call before arriving.</p>'
                    .'<h2>Shipping FAQ</h2>'
                    .'<ul>'
                    .'<li><strong>Can I change the delivery address after ordering?</strong> Yes, only if the order has not shipped yet. Reach out via <a href="/contact">Contact us</a> with your order number and the new address.</li>'
                    .'<li><strong>Do you ship outside Egypt?</strong> Not yet — we are working on it.</li>'
                    .'<li><strong>How do I track my order?</strong> Every order has a tracking link sent by SMS and email. You can also find it under <a href="/orders">My Orders</a>.</li>'
                    .'</ul>',
                'content_ar' => '<h2>مدة التجهيز</h2>'
                    .'<p>الطلبات المقدمة قبل <strong>2 ظهراً</strong> تُشحن في نفس اليوم، وما بعد ذلك في يوم العمل التالي.</p>'
                    .'<p>ستصلك رسالة <strong>قصيرة وبريد إلكتروني</strong> مع رابط التتبع بمجرد خروج طلبك من المستودع.</p>'
                    .'<h2>مدة التوصيل والرسوم</h2>'
                    .'<table>'
                    .'<thead><tr><th>المنطقة</th><th>المدة</th><th>الرسوم</th></tr></thead>'
                    .'<tbody>'
                    .'<tr><td>القاهرة والجيزة</td><td>1–2 يوم عمل</td><td>60 جنيه</td></tr>'
                    .'<tr><td>الإسكندرية والدلتا</td><td>2–3 أيام عمل</td><td>75 جنيه</td></tr>'
                    .'<tr><td>الصعيد والقناة</td><td>3–5 أيام عمل</td><td>90 جنيه</td></tr>'
                    .'<tr><td>المناطق النائية</td><td>5–7 أيام عمل</td><td>110 جنيه</td></tr>'
                    .'</tbody></table>'
                    .'<h2>معلومة مهمة</h2>'
                    .'<p>سيحاول مندوب التوصيل التسليم <strong>مرتين</strong>. أبقِ هاتفك متصلاً — سيتصل بك قبل الوصول.</p>'
                    .'<h2>أسئلة الشحن</h2>'
                    .'<ul>'
                    .'<li><strong>هل يمكنني تغيير عنوان التوصيل بعد الطلب؟</strong> نعم، بشرط ألا يكون الطلب قد شُحن بالفعل. تواصل معنا عبر <a href="/contact">صفحة التواصل</a> مع رقم الطلب والعنوان الجديد.</li>'
                    .'<li><strong>هل تشحنون خارج مصر؟</strong> لا حتى الآن — نعمل على ذلك.</li>'
                    .'<li><strong>كيف أتتبع طلبي؟</strong> لكل طلب رابط تتبع يُرسل عبر رسالة وبريد إلكتروني،تجده أيضاً في <a href="/orders">طلباتي</a>.</li>'
                    .'</ul>',
            ],
            [
                'slug' => 'returns',
                'title' => 'Returns Policy',
                'title_ar' => 'سياسة الإرجاع',
                'content' => '<h2>How to return</h2>'
                    .'<ol>'
                    .'<li><strong>Request it.</strong> Open the order in <a href="/orders">My Orders</a> and tap <strong>Return item</strong>.</li>'
                    .'<li><strong>Pack it.</strong> Tags on, unworn, original packaging. We will email a prepaid label.</li>'
                    .'<li><strong>We collect.</strong> A courier picks the package up from your door — at no cost to you.</li>'
                    .'</ol>'
                    .'<h2>What can be returned</h2>'
                    .'<p>Items can be returned within <strong>14 days</strong> of delivery, as long as they are unworn, unwashed, and the original tags are still attached. The item must be in its original packaging.</p>'
                    .'<p>We inspect every return within <strong>48 hours</strong> of receiving it. If everything checks out, the refund is triggered the same day.</p>'
                    .'<h2>How you get refunded</h2>'
                    .'<table>'
                    .'<thead><tr><th>Paid with</th><th>Refund to</th><th>Time</th></tr></thead>'
                    .'<tbody>'
                    .'<tr><td>Card</td><td>Original card</td><td>5–7 business days</td></tr>'
                    .'<tr><td>Cash on delivery</td><td>Store credit or wallet transfer</td><td>3–5 business days</td></tr>'
                    .'</tbody></table>'
                    .'<h2>Not returnable</h2>'
                    .'<p>Final Sale items, worn or washed pieces, and anything that has been altered or monogrammed.</p>'
                    .'<h2>Returns FAQ</h2>'
                    .'<ul>'
                    .'<li><strong>Who pays for return shipping?</strong> We do. Every return inside the 14-day window includes a free courier pickup.</li>'
                    .'<li><strong>I missed the return window. What now?</strong> Reach out via <a href="/contact">Contact us</a> — we review every case individually.</li>'
                    .'</ul>',
                'content_ar' => '<h2>كيفية الإرجاع</h2>'
                    .'<ol>'
                    .'<li><strong>اطلب الإرجاع.</strong> افتح الطلب في <a href="/orders">طلباتي</a> واضغط <strong>إرجاع القطعة</strong>.</li>'
                    .'<li><strong>جهّز الطرد.</strong> البطاقات موجودة، القطعة غير مستخدمة، وفي عبوتها الأصلية. سنرسل لك بريداً إلكترونياً يشمل ملصقاً مدفوعاً.</li>'
                    .'<li><strong>نستلمها.</strong> المندوب يستلم الطرد من بابك — دون أي تكلفة عليك.</li>'
                    .'</ol>'
                    .'<h2>ما يمكن إرجاعه</h2>'
                    .'<p>يمكن إرجاع القطع خلال <strong>14 يوماً</strong> من الاستلام، بشرط أن تكون غير مستخدمة وغير مغسولة مع وجود البطاقات الأصلية، وأن تكون في عبوتها الأصلية.</p>'
                    .'<p>نفحص كل عملية إرجاع خلال <strong>48 ساعة</strong> من استلامها، وإذا كانت كل الشروط مستوفاة يتم تنفيذ الاسترداد في نفس اليوم.</p>'
                    .'<h2>كيف يتم الاسترداد</h2>'
                    .'<table>'
                    .'<thead><tr><th>طريقة الدفع</th><th>يُسترد إلى</th><th>المدة</th></tr></thead>'
                    .'<tbody>'
                    .'<tr><td>بطاقة</td><td>البطاقة الأصلية</td><td>5–7 أيام عمل</td></tr>'
                    .'<tr><td>الدفع عند الاستلام</td><td>رصيد المتجر أو تحويل للمحفظة</td><td>3–5 أيام عمل</td></tr>'
                    .'</tbody></table>'
                    .'<h2>غير قابل للإرجاع</h2>'
                    .'<p>المنتجات الختامية، والقطع المستخدمة أو المغسولة، وأي شيء تم تعديله أو تطريزه.</p>'
                    .'<h2>أسئلة الإرجاع</h2>'
                    .'<ul>'
                    .'<li><strong>من يدفع رسوم شحن الإرجاع؟</strong> نحن ندفع — كل إرجاع خلال 14 يوماً يشمل استلاماً مجانياً.</li>'
                    .'<li><strong>فاتت مدة الإرجاع. ماذا أفعل؟</strong> تواصل معنا عبر <a href="/contact">صفحة التواصل</a> — نراجع كل حالة على حدة.</li>'
                    .'</ul>',
            ],
            [
                'slug' => 'exchange',
                'title' => 'Exchange Policy',
                'title_ar' => 'سياسة الاستبدال',
                'content' => '<h2>How to exchange</h2>'
                    .'<ol>'
                    .'<li><strong>Pick the new size.</strong> Open the size guide to confirm before you request the swap.</li>'
                    .'<li><strong>Request it.</strong> Message <a href="/contact">Contact us</a> with your order number and the size or color you want.</li>'
                    .'<li><strong>We swap it.</strong> We arrange the pickup and ship the replacement as soon as the original is on its way back.</li>'
                    .'</ol>'
                    .'<h2>Conditions</h2>'
                    .'<p>Exchanges are accepted within <strong>14 days</strong> of delivery. The item must be unworn, with original tags attached, and the new size or color must be in stock.</p>'
                    .'<p>Each order includes <strong>one free exchange</strong>. Any further size swaps are treated as a return plus a new order.</p>'
                    .'<h2>Price difference</h2>'
                    .'<p>If the new item costs more, you pay the difference. If it costs less, we refund the difference as <strong>store credit</strong>.</p>'
                    .'<h2>Exchange FAQ</h2>'
                    .'<ul>'
                    .'<li><strong>The size I want is out of stock.</strong> We can put you on a waitlist for the next restock, or refund the difference as store credit.</li>'
                    .'<li><strong>Is the exchange shipping free?</strong> Yes — the first exchange per order is on us, both ways.</li>'
                    .'</ul>',
                'content_ar' => '<h2>كيفية الاستبدال</h2>'
                    .'<ol>'
                    .'<li><strong>اختر المقاس الجديد.</strong> راجع دليل المقاسات للتأكد قبل طلب الاستبدال.</li>'
                    .'<li><strong>اطلب الاستبدال.</strong> راسلنا عبر <a href="/contact">صفحة التواصل</a> مع رقم الطلب والمقاس أو اللون المطلوب.</li>'
                    .'<li><strong>نستبدل لك.</strong> ننظم الاستلام ونشحن القطعة البديلة بمجرد عودان القطعة الأصلية.</li>'
                    .'</ol>'
                    .'<h2>الشروط</h2>'
                    .'<p>يُقبل الاستبدال خلال <strong>14 يوماً</strong> من الاستلام. يجب أن تكون القطعة غير مستخدمة، مع وجود البطاقات الأصلية، وأن يكون المقاس أو اللون المطلوب متوفراً.</p>'
                    .'<p>كل طلب يشمل <strong>استبدالاً مجانياً واحداً</strong>. أي تبديل إضافي للمقاس يُعامل كإرجاع مع طلب جديد.</p>'
                    .'<h2>فرق السعر</h2>'
                    .'<p>إذا كانت القطعة الجديدة أغلى، تدفع الفرق. وإذا كانت أرخص، نعيد الفرق على شكل <strong>رصيد متجر</strong>.</p>'
                    .'<h2>أسئلة الاستبدال</h2>'
                    .'<ul>'
                    .'<li><strong>المقاس الذي أريده غير متوفر.</strong> يمكننا وضعك على قائمة انتظار حتى موعد التوفير التالي، أو إعادة الفرق كرصيد متجر.</li>'
                    .'<li><strong>هل شحن الاستبدال مجاني؟</strong> نعم — أول استبدال في كل الطلب علينا، في الاتجاهين.</li>'
                    .'</ul>',
            ],
        ];

        foreach ($pages as $page) {
            $record = Page::firstOrCreate(
                ['slug' => $page['slug']],
                [
                    'title' => $page['title'],
                    'title_ar' => $page['title_ar'],
                    'is_active' => true,
                ],
            );

            // Fill an empty page only — an admin's copy is never
            // overwritten by a re-run of the seeder. `blank()` covers a
            // null column as well as a page saved with an empty body,
            // so a page can never reach the storefront with no copy at
            // all. Each language is checked on its own: a page that
            // has an English body but no Arabic one still gets the
            // Arabic copy.
            $dirty = false;

            if (blank($record->content)) {
                $record->content = $page['content'];
                $dirty = true;
            }

            if (blank($record->content_ar)) {
                $record->content_ar = $page['content_ar'];
                $dirty = true;
            }

            if ($dirty) {
                $record->save();
            }
        }
    }
}
