# Remote VieFund Query Flow (Simplified)

This diagram shows how the page data is assembled from Fund and Trust transaction sources.

```mermaid
flowchart TD
    subgraph FundPath[Fund path]
        L[UB_FundTrxLookup] --> T[UB_FundTrx]
        L --> FC[UB_FundTrxCash]
        FC --> CT[UB_CashTrx]
        CT --> TS[UB_Def_TrxStatus]
        L --> P1[UB_Plan]
        P1 --> C1[UB_Customer]
        L --> TT[UB_Def_TrxType]
        TRL[UB_TrustTrx linked] --> T
    end

    subgraph TrustPath[Trust path]
        TR[UB_TrustTrx standalone] --> P2[UB_Plan]
        P2 --> C2[UB_Customer]
        TR --> TTY[UB_Def_TrustType]
        TR --> TDY[UB_Def_TrustDepositType]
    end

    FundRows[Fund result rows] --> Merge[Merge sort paginate in PHP]
    TrustRows[Trust result rows] --> Merge

    FundPath --> FundRows
    TrustPath --> TrustRows

    Merge --> UI[Remote VieFund table + export]
```

## What this means

- Fund rows are built from Fund + Cash tables, then enriched with customer/type/status lookups.
- Trust rows are queried separately when they are standalone (`iTrxID = 0`).
- Trust rows that point to a fund transaction (`iTrxID > 0`) are attached in the Fund path as linked trust details.
- Both sets are merged later for presentation and export.
