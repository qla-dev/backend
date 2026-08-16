param(
    [Parameter(Mandatory = $true)]
    [string] $Path
)

$OutputEncoding = [Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$connectionString = "Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$Path;Extended Properties='Excel 12.0 Xml;HDR=NO;IMEX=1'"
$connection = New-Object System.Data.OleDb.OleDbConnection($connectionString)
$connection.Open()
$records = @()

function Normalize-Header([string] $Value) {
    return (($Value -replace '\s+', ' ').Trim()).ToLowerInvariant()
}

try {
    $sheets = @(
        @('ROAD$', 'road'),
        @('AIR$', 'air'),
        @('FCL$', 'sea_fcl'),
        @("'LCL $'", 'sea_lcl'),
        @('RAIL$', 'rail'),
        @('CUSTOMS$', 'customs')
    )

    foreach ($sheet in $sheets) {
        $command = $connection.CreateCommand()
        # The workbook has formatting far below its actual data. Limiting the
        # read avoids scanning thousands of visually empty formatted rows.
        $command.CommandText = "SELECT TOP 500 * FROM [$($sheet[0])]"
        $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($command)
        $table = New-Object System.Data.DataTable
        [void] $adapter.Fill($table)

        $headerRow = -1
        $columns = @{}
        for ($rowIndex = 0; $rowIndex -lt [Math]::Min(5, $table.Rows.Count); $rowIndex++) {
            for ($columnIndex = 0; $columnIndex -lt $table.Columns.Count; $columnIndex++) {
                $header = Normalize-Header ([string] $table.Rows[$rowIndex][$columnIndex])
                if ($header -eq 'booking reference') { $headerRow = $rowIndex }
                if ($header) { $columns[$header] = $columnIndex }
            }
        }

        if ($headerRow -lt 0) { throw "Booking reference header not found in $($sheet[0])." }

        function Cell([string] $Header) {
            $key = Normalize-Header $Header
            if (-not $columns.ContainsKey($key)) { return '' }
            $value = $table.Rows[$script:currentRow][$columns[$key]]
            if ($null -eq $value -or $value -is [DBNull]) { return '' }
            if ($value -is [DateTime]) { return $value.ToString('MM/dd/yyyy') }
            return ([string] $value).Trim()
        }

        for ($script:currentRow = $headerRow + 1; $script:currentRow -lt $table.Rows.Count; $script:currentRow++) {
            $booking = Cell 'booking reference'
            $consignee = Cell 'consignee'
            $date = Cell 'date/datum'
            if (-not $booking -or -not $consignee -or -not $date) { continue }

            $yearMatch = [regex]::Match($date, '(20\d{2})')
            if (-not $yearMatch.Success -or $yearMatch.Groups[1].Value -ne '2026') { continue }

            $records += [ordered]@{
                sheet = $sheet[1]
                source_row = $script:currentRow + 1
                date = $date
                status = Cell 'shipment status'
                booking = $booking
                insurance = Cell 'insurance'
                department = Cell 'department'
                freight_mode = Cell 'freight mode'
                consignee = $consignee
                subdepartment = Cell 'subdepartment'
                kgs = Cell 'kgs'
                quantity = Cell 'qty/g.w./meas'
                cbm = Cell 'cbm'
                teu = Cell 'teu'
                container_types = Cell 'container types'
                container = Cell 'container'
                departure = Cell 'departure port / station'
                arrival = Cell 'arrival port / station'
                etd = Cell 'etd date'
                eta = Cell 'eta date'
                atd = Cell 'atd date'
                shipper = Cell 'shipper name'
                mediator = Cell 'mediator'
                incoterms = Cell 'incoterms'
                price = Cell 'price + insurance'
                profit_loss = Cell 'gp (profit & loss)'
            }
        }
    }
} finally {
    $connection.Close()
}

$records | ConvertTo-Json -Depth 4 -Compress
