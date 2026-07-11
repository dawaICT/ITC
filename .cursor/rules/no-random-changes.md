# No Random Changes Rule

Do not make broad unrelated changes.

Before editing:
- Read the file.
- Find related includes, session checks, database connection, and role checks.
- Identify dependencies.
- Explain what will change.

After editing:
- Summarize changed files.
- Explain why each change was necessary.
- Mention possible risks.
- Suggest manual test steps.

Never delete existing functionality unless the user explicitly asks.
Never rename database columns unless a migration plan is provided.
Never change database connection files unless the task is specifically about connection or security.