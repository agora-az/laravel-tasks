@extends('layouts.app')

@section('title', 'Fee Matching Results')

@section('content')
    <h2 style="margin-bottom: 30px;">Fee Transaction Matching Results</h2>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 20px;">
            {{ session('success') }}
        </div>
    @endif

    <!-- Summary Statistics -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px;">
        <!-- Account Fees Summary -->
        <div class="card" style="background: linear-gradient(135deg, #805ad5 0%, #553c9a 100%); color: white; padding: 25px; text-align: center; border-radius: 8px;">
            <div style="font-size: 28px; font-weight: bold; margin-bottom: 8px;">{{ number_format($summary['account_fees']['total']) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Account Fees Total</div>
            <div style="margin-top: 12px; font-size: 12px; opacity: 0.8;">
                <span style="color: #a78bfa;">{{ $summary['account_fees']['matched'] }} matched</span> • 
                <span style="color: #ddd6fe;">{{ $summary['account_fees']['unmatched'] }} unmatched</span>
            </div>
        </div>

        <!-- Advisory Fees Summary -->
        <div class="card" style="background: linear-gradient(135deg, #d69e2e 0%, #7d6608 100%); color: white; padding: 25px; text-align: center; border-radius: 8px;">
            <div style="font-size: 28px; font-weight: bold; margin-bottom: 8px;">{{ number_format($summary['advisory_fees']['total']) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Advisory Fees Total</div>
            <div style="margin-top: 12px; font-size: 12px; opacity: 0.8;">
                <span style="color: #fbbf24;">{{ $summary['advisory_fees']['matched'] }} matched</span> • 
                <span style="color: #fde68a;">{{ $summary['advisory_fees']['unmatched'] }} unmatched</span>
            </div>
        </div>

        <!-- Account Fees Match Rate -->
        <div class="card" style="background: #f7fafc; padding: 25px; border-radius: 8px; border-left: 4px solid #805ad5;">
            <div style="font-size: 24px; font-weight: bold; color: #805ad5; margin-bottom: 8px;">{{ $summary['account_fees']['match_percentage'] }}%</div>
            <div style="font-size: 13px; color: #4a5568;">Account Fees Matched</div>
            <div style="font-size: 12px; color: #718096; margin-top: 8px;">
                {{ $summary['account_fees']['matched'] }} of {{ $summary['account_fees']['total'] }} matched
            </div>
        </div>

        <!-- Advisory Fees Match Rate -->
        <div class="card" style="background: #f7fafc; padding: 25px; border-radius: 8px; border-left: 4px solid #d69e2e;">
            <div style="font-size: 24px; font-weight: bold; color: #d69e2e; margin-bottom: 8px;">{{ $summary['advisory_fees']['match_percentage'] }}%</div>
            <div style="font-size: 13px; color: #4a5568;">Advisory Fees Matched</div>
            <div style="font-size: 12px; color: #718096; margin-top: 8px;">
                {{ $summary['advisory_fees']['matched'] }} of {{ $summary['advisory_fees']['total'] }} matched
            </div>
        </div>
    </div>

    <!-- Tabs for Unmatched Records -->
    <div style="margin-bottom: 40px;">
        <div style="border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
            <div style="display: flex; gap: 20px;">
                <button onclick="switchTab('account-fees-tab', 'account-fees-content')" 
                        id="account-fees-tab" 
                        style="padding: 12px 20px; border: none; background: none; font-size: 16px; font-weight: 600; color: #805ad5; border-bottom: 3px solid #805ad5; cursor: pointer;">
                    Unmatched Account Fees ({{ $unmatchedAccountFees->total() }})
                </button>
                <button onclick="switchTab('advisory-fees-tab', 'advisory-fees-content')" 
                        id="advisory-fees-tab" 
                        style="padding: 12px 20px; border: none; background: none; font-size: 16px; font-weight: 600; color: #718096; cursor: pointer;">
                    Unmatched Advisory Fees ({{ $unmatchedAdvisoryFees->total() }})
                </button>
            </div>
        </div>

        <!-- Unmatched Account Fees Tab -->
        <div id="account-fees-content" style="display: block;">
            <div class="card" style="padding: 25px; border-radius: 8px;">
                @if($unmatchedAccountFees->count() > 0)
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Rep Code</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Client Name</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Type</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Amount</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($unmatchedAccountFees as $fee)
                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                        <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $fee->rep_code }}</td>
                                        <td style="padding: 12px; color: #2d3748;">{{ $fee->client_name }}</td>
                                        <td style="padding: 12px; color: #4a5568;">{{ $fee->transaction_type }}</td>
                                        <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                            ${{ number_format($fee->amount, 2) }}
                                        </td>
                                        <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $fee->trade_date }}</td>
                                        <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $fee->settlement_date }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div style="margin-top: 20px; display: flex; justify-content: center;">
                        {{ $unmatchedAccountFees->onEachSide(1)->links() }}
                    </div>
                @else
                    <div style="text-align: center; padding: 40px; color: #718096;">
                        <p style="font-size: 18px; margin-bottom: 10px;">✅ All account fees matched!</p>
                        <p>No unmatched account fees found.</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Unmatched Advisory Fees Tab -->
        <div id="advisory-fees-content" style="display: none;">
            <div class="card" style="padding: 25px; border-radius: 8px;">
                @if($unmatchedAdvisoryFees->count() > 0)
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Rep Code</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Full Name</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Type</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Amount</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Effective Date</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($unmatchedAdvisoryFees as $fee)
                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                        <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $fee->rep_code }}</td>
                                        <td style="padding: 12px; color: #2d3748;">{{ $fee->full_name }}</td>
                                        <td style="padding: 12px; color: #4a5568;">{{ $fee->transaction_type }}</td>
                                        <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                            ${{ number_format($fee->amount, 2) }}
                                        </td>
                                        <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $fee->effective_date }}</td>
                                        <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $fee->settlement_date }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div style="margin-top: 20px; display: flex; justify-content: center;">
                        {{ $unmatchedAdvisoryFees->onEachSide(1)->links() }}
                    </div>
                @else
                    <div style="text-align: center; padding: 40px; color: #718096;">
                        <p style="font-size: 18px; margin-bottom: 10px;">✅ All advisory fees matched!</p>
                        <p>No unmatched advisory fees found.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div style="display: flex; gap: 10px;">
        <a href="{{ route('reconciliations.matches') }}" class="btn" style="background: #3182ce; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
            View All Matches
        </a>
        <a href="{{ route('dashboard') }}" class="btn" style="background: #718096; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
            Back to Dashboard
        </a>
    </div>

    <script>
        function switchTab(tabId, contentId) {
            // Hide all contents
            const allContents = document.querySelectorAll('[id$="-content"]');
            allContents.forEach(content => content.style.display = 'none');
            
            // Deactivate all tabs
            const allTabs = document.querySelectorAll('[id$="-tab"]');
            allTabs.forEach(tab => {
                tab.style.color = '#718096';
                tab.style.borderBottomColor = 'transparent';
            });
            
            // Activate selected tab and content
            document.getElementById(contentId).style.display = 'block';
            const activeTab = document.getElementById(tabId);
            activeTab.style.color = activeTab.id === 'account-fees-tab' ? '#805ad5' : '#d69e2e';
            activeTab.style.borderBottomColor = activeTab.style.color;
        }
    </script>
@endsection
