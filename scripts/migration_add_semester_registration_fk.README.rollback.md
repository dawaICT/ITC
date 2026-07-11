Rollback instructions for migration_add_semester_registration_fk

To apply the rollback SQL (ensure you have a DB backup first):

```bash
mysql -u root -p wucportal < scripts/migration_add_semester_registration_fk.rollback.sql
```

If you prefer to run interactively, connect and run the statements shown in the `.sql` file.

If `DROP FOREIGN KEY` fails because the constraint name differs, inspect the table:

```sql
SHOW CREATE TABLE `course_registration`;
```

Then identify and drop the appropriate foreign key and index names.
