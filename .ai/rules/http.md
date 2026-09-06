---
paths:
  - 'app/Http/**'
---

# Http

## API conventions: gate split, 409 conflicts, Scalar groups
Domain routes register each apiResource twice: index/show behind the domain's view gate, store/update/destroy behind its manage gate (see routes/api.php financial group).
Deletes blocked by state (children, attached records) abort with 409 and a plain message — never surface raw FK errors; plain deletes only for unreferenced models.
Responses are always data-wrapped Eloquent API Resources. Controllers carry #[Group('Domain / Resource')] (Dedoc\Scramble\Attributes\Group) so Scalar docs cluster by domain; cross-domain controllers use a bare group name ('Notes').
