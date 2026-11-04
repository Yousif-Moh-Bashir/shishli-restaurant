<?php

namespace App\Services\Finance;

final class ValidationMessages
{
    public static function messages(): array
    {
        return [
            'required' => ':attribute مطلوب.', 'prohibited' => ':attribute غير مسموح في هذه العملية.',
            'string' => ':attribute يجب أن يكون نصًا.', 'array' => ':attribute يجب أن يكون قائمة صحيحة.',
            'uuid' => ':attribute غير صالح.', 'exists' => ':attribute غير متاح.',
            'unique' => ':attribute مستخدم بالفعل.', 'distinct' => ':attribute مكرر.',
            'in' => 'اختر قيمة صحيحة للحقل :attribute.', 'boolean' => ':attribute غير صالح.',
            'numeric' => ':attribute يجب أن يكون رقمًا.', 'integer' => ':attribute يجب أن يكون عددًا صحيحًا.',
            'regex' => ':attribute يجب أن يكون مبلغًا غير سالب، حتى 12 رقمًا ومنزلتين عشريتين.',
            'gt.numeric' => ':attribute يجب أن يكون أكبر من :value.',
            'min.string' => ':attribute يجب أن يحتوي على :min أحرف على الأقل.',
            'max.string' => ':attribute يجب ألا يتجاوز :max حرفًا.',
            'min.array' => 'اختر عنصرًا واحدًا على الأقل في :attribute.',
            'max.array' => ':attribute يتجاوز العدد المسموح.',
            'min.numeric' => ':attribute أقل من الحد المسموح.',
            'date_format' => ':attribute يجب أن يكون تاريخًا صحيحًا.',
            'before_or_equal' => ':attribute لا يمكن أن يكون في المستقبل.',
            'after_or_equal' => 'نهاية الفترة يجب أن تكون بعد بدايتها أو مساوية لها.',
            'file' => ':attribute يجب أن يكون ملفًا صالحًا.',
            'mimes' => ':attribute يجب أن يكون PDF أو JPG أو PNG.',
            'extensions' => 'امتداد :attribute غير مسموح.',
            'max.file' => 'حجم :attribute يجب ألا يتجاوز 10 ميغابايت.',
            'email' => 'أدخل بريدًا إلكترونيًا صحيحًا.',
            'password.mixed' => 'كلمة المرور يجب أن تحتوي أحرفًا كبيرة وصغيرة.',
            'password.numbers' => 'كلمة المرور يجب أن تحتوي أرقامًا.',
        ];
    }

    public static function attributes(): array
    {
        return [
            'branch_uuid' => 'الفرع', 'date' => 'التاريخ', 'amount' => 'المبلغ', 'notes' => 'الملاحظات',
            'lines' => 'مصادر الإيراد', 'lines.*.amount' => 'مبلغ المصدر', 'lines.*.source_uuid' => 'مصدر الإيراد',
            'version' => 'إصدار السجل', 'reason' => 'سبب التعديل', 'description' => 'الوصف',
            'payment_method' => 'طريقة الدفع', 'attachment' => 'المرفق', 'category_uuid' => 'التصنيف',
            'supplier_uuid' => 'المورد', 'invoice_number' => 'رقم الفاتورة', 'name' => 'الاسم',
            'is_active' => 'الحالة', 'phone' => 'الهاتف', 'tax_number' => 'الرقم الضريبي',
            'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور', 'role' => 'الدور المالي',
            'branch_uuids' => 'الفروع المسموحة', 'branch_uuids.*' => 'الفرع',
            'from' => 'بداية الفترة', 'to' => 'نهاية الفترة', 'source_uuid' => 'مصدر الإيراد',
            'status' => 'الحالة', 'group_by' => 'تجميع التقرير', 'page' => 'رقم الصفحة',
        ];
    }
}
