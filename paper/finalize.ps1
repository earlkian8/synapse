# Opens the built .docx in Word, builds the table of contents, repaginates, and
# exports a PDF next to it. Run after build_docx.py.
param(
  [string]$Docx = (Join-Path (Split-Path -Parent (Split-Path -Parent $PSCommandPath)) 'SYNAPSE-Chapter-3.docx')
)
$ErrorActionPreference = 'Stop'
$Docx = (Resolve-Path $Docx).Path
$Pdf  = [IO.Path]::ChangeExtension($Docx, '.pdf')

$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
try {
  $doc = $word.Documents.Open($Docx, $false, $false)
  foreach ($toc in $doc.TablesOfContents) { $toc.Update() }
  $doc.Fields.Update() | Out-Null
  $doc.Repaginate()
  $pages = $doc.ComputeStatistics(2)   # wdStatisticPages
  $words = $doc.ComputeStatistics(0)
  $doc.Save()
  $doc.ExportAsFixedFormat($Pdf, 17)   # wdExportFormatPDF
  $doc.Close(0)
  "pages=$pages words=$words"
  "pdf=$Pdf"
} finally {
  $word.Quit()
  [void][Runtime.InteropServices.Marshal]::ReleaseComObject($word)
}
