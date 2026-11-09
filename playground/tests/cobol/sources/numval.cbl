       IDENTIFICATION DIVISION.
       PROGRAM-ID. NUMVAL.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01  V PIC X(8).
       01  N PIC S9(5)V99.
       01  D PIC -(5)9.99.
       PROCEDURE DIVISION.
           MOVE SPACES TO V PERFORM P.
           MOVE '12 3' TO V PERFORM P.
           MOVE '+5' TO V PERFORM P.
           MOVE '5-' TO V PERFORM P.
           MOVE '.5' TO V PERFORM P.
           MOVE '5.' TO V PERFORM P.
           MOVE '  -7.25' TO V PERFORM P.
           MOVE '1.2.3' TO V PERFORM P.
           MOVE '- 4' TO V PERFORM P.
           MOVE 'ABC' TO V PERFORM P.
           MOVE '123456789' TO V PERFORM P.
           STOP RUN.
       P.
           COMPUTE N = FUNCTION NUMVAL(V).
           MOVE N TO D.
           DISPLAY '[' V '] ' FUNCTION TEST-NUMVAL(V) ' ' D.
