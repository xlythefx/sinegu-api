# Database Migrations

This directory contains SQL migration files for restructuring the invoice system.

## Migration Order

1. **001_create_invoices_table.sql** - Creates the new `invoices` table
2. **002_create_invoice_trades_table.sql** - Creates junction table for linking trades to invoices
3. **003_create_invoice_payments_table.sql** - Creates payment tracking table
4. **004_migrate_existing_invoice_data.sql** - Migrates data from old `invoice` table to new structure

## Important Notes

⚠️ **BACKUP YOUR DATABASE BEFORE RUNNING MIGRATIONS!**

```sql
-- Create backup
CREATE TABLE invoice_backup AS SELECT * FROM invoice;
CREATE TABLE accounts_backup AS SELECT * FROM accounts;
CREATE TABLE trades_backup AS SELECT * FROM trades;
```

## Running Migrations

### Option 1: Using phpMyAdmin
1. Open phpMyAdmin
2. Select your database
3. Go to SQL tab
4. Run each migration file in order (001, 002, 003, 004)

### Option 2: Using MySQL Command Line
```bash
mysql -u your_username -p your_database < migrations/001_create_invoices_table.sql
mysql -u your_username -p your_database < migrations/002_create_invoice_trades_table.sql
mysql -u your_username -p your_database < migrations/003_create_invoice_payments_table.sql
mysql -u your_username -p your_database < migrations/004_migrate_existing_invoice_data.sql
```

## New Architecture Benefits

### Old Structure Issues:
- ❌ `invoice` table used `user_id` but it was actually `account_id` (confusing)
- ❌ No clear invoice period tracking
- ❌ Payment status inferred from newer invoices (not explicit)
- ❌ No link between trades and invoice periods
- ❌ PNL stored but unclear what period it covers
- ❌ No payment transaction tracking

### New Structure Benefits:
- ✅ Clear `account_id` relationship
- ✅ Explicit `invoice_month` for period tracking
- ✅ `status` field for payment state
- ✅ `invoice_trades` table links trades to invoices
- ✅ `invoice_payments` table tracks payment transactions
- ✅ Proper foreign keys and indexes
- ✅ Better query performance with proper indexing

## Table Relationships

```
accounts (1) ──< (many) invoices
invoices (1) ──< (many) invoice_trades >── (many) trades
invoices (1) ──< (many) invoice_payments
```

## After Migration

1. Update PHP files to use new table structure:
   - `users/get_invoices.php`
   - `mark_invoice_paid.php`
   - `account_invoice.php`
   - Any other files referencing the old `invoice` table

2. Test invoice generation and payment flows

3. Once verified, you can drop the old `invoice` table:
   ```sql
   -- Only after confirming everything works!
   DROP TABLE invoice;
   ```

